<?php

namespace App\Services\Dav;

use App\Models\AddressBook;
use App\Models\AddressBookInstance;
use App\Models\CalendarInstance;
use App\Models\User;
use App\Services\Push\PushChanges;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Sabre\DAV\Sharing\Plugin as SharingPlugin;

/**
 * Shares of calendars and address books (PR 21, docs/design/sharing.md).
 *
 * A calendar share is a calendarinstances row of the sharee with access 2 or
 * 3; an address book share is an addressbookinstances row. Events, cards and
 * the change log stay on the owner's collection. Shares are created accepted.
 * Disabling the owner or the sharee keeps the share (Settled question 1).
 *
 * Only the admin panel shares (Decision 2). Nothing here is reachable over DAV.
 */
final class SharingService
{
    public const READ = SharingPlugin::ACCESS_READ;

    public const READ_WRITE = SharingPlugin::ACCESS_READWRITE;

    private const SHARED = [self::READ, self::READ_WRITE];

    private const ACCEPTED = SharingPlugin::INVITE_ACCEPTED;

    private const NAME_LENGTH = 255;

    private const SHAREE_NAME_LENGTH = 100;

    /**
     * A unique key can still fire when a writer that does not take the
     * principal lock inserts the same name. Each attempt is a new transaction,
     * because PostgreSQL aborts the one that hit the key.
     */
    private const ATTEMPTS = 5;

    /**
     * Shares the collection with the user, or changes the access of the
     * share they already have. Returns the sharee's instance.
     */
    public function share(CalendarInstance|AddressBook $collection, User $sharee, int $access): CalendarInstance|AddressBookInstance
    {
        $this->assertAccess($access);
        $owner = $this->owner($collection);
        $principal = $sharee->principal_uri;

        if ($principal === $owner->principal_uri) {
            throw new InvalidArgumentException('A collection cannot be shared with its owner.');
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn (): CalendarInstance|AddressBookInstance => $collection instanceof CalendarInstance
                    ? $this->shareCalendar($collection, $owner, $sharee, $access)
                    : $this->shareAddressBook($collection, $owner, $sharee, $access));
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= self::ATTEMPTS) {
                    throw $exception;
                }
            }
        }
    }

    public function changeAccess(CalendarInstance|AddressBook $collection, User $sharee, int $access): void
    {
        $this->assertAccess($access);
        $this->owner($collection);

        $changed = $this->shareQuery($collection, $sharee)->update(['access' => $access]);

        if ($changed === 0) {
            throw new InvalidArgumentException('That user has no share of this collection.');
        }
    }

    /**
     * Removes the user's share, with the locks and properties stored under
     * their path for it. Returns false when there was no share.
     */
    public function unshare(CalendarInstance|AddressBook $collection, User $sharee): bool
    {
        $this->owner($collection);

        return DB::transaction(function () use ($collection, $sharee): bool {
            $share = $this->shareQuery($collection, $sharee)->first(['id', 'uri']);

            if ($share === null) {
                return false;
            }

            $this->shareQuery($collection, $sharee)->delete();
            app(DavDataCleaner::class)->deletePath(self::sharePath($collection instanceof CalendarInstance, $sharee->principal_uri, (string) $share->uri));
            app(PushChanges::class)->homeChanged($collection instanceof CalendarInstance, $sharee->principal_uri);

            return true;
        });
    }

    /**
     * The sharees' instances of a collection, oldest share first.
     *
     * @return Collection<int, CalendarInstance>|Collection<int, AddressBookInstance>
     */
    public function sharees(CalendarInstance|AddressBook $collection): Collection
    {
        $this->owner($collection);

        if ($collection instanceof CalendarInstance) {
            return CalendarInstance::query()
                ->where('calendarid', $collection->calendarid)
                ->whereIn('access', self::SHARED)
                ->orderBy('id')
                ->get();
        }

        return AddressBookInstance::query()
            ->where('addressbookid', $collection->getKey())
            ->orderBy('id')
            ->get();
    }

    /**
     * Calendars shared with the user. ownerInstance names the owner's copy.
     *
     * @return Collection<int, CalendarInstance>
     */
    public function receivedCalendarShares(User $user): Collection
    {
        return CalendarInstance::query()
            ->with('ownerInstance')
            ->where('principaluri', $user->principal_uri)
            ->whereIn('access', self::SHARED)
            ->orderBy('id')
            ->get();
    }

    /**
     * Address books shared with the user. addressBook is the owner's.
     *
     * @return Collection<int, AddressBookInstance>
     */
    public function receivedAddressBookShares(User $user): Collection
    {
        return AddressBookInstance::query()
            ->with('addressBook')
            ->where('principaluri', $user->principal_uri)
            ->orderBy('id')
            ->get();
    }

    /**
     * Every principal that sees the collection, and where: the owner (access
     * 1) first, then the sharees. A change to the collection is a change for
     * each of these paths (M5 sends push messages to them).
     *
     * @return list<array{principaluri: string, uri: string, access: int}>
     */
    public function instances(CalendarInstance|AddressBook $collection): array
    {
        $owner = $this->owner($collection);

        $rows = $collection instanceof CalendarInstance
            ? DB::table('calendarinstances')->where('calendarid', $collection->calendarid)->orderBy('access')->orderBy('id')->get(['principaluri', 'uri', 'access'])
            : collect([(object) ['principaluri' => $owner->principal_uri, 'uri' => $collection->uri, 'access' => CalendarInstance::OWNER]])
                ->concat(DB::table('addressbookinstances')->where('addressbookid', $collection->getKey())->orderBy('id')->get(['principaluri', 'uri', 'access']));

        $instances = [];

        foreach ($rows as $row) {
            $instances[] = [
                'principaluri' => (string) $row->principaluri,
                'uri' => (string) $row->uri,
                'access' => (int) $row->access,
            ];
        }

        return $instances;
    }

    /**
     * The path of a share in the sharee's home, as property storage and
     * locks store it.
     */
    public static function sharePath(bool $calendar, string $principalUri, string $uri): string
    {
        $username = str_starts_with($principalUri, 'principals/') ? substr($principalUri, strlen('principals/')) : $principalUri;

        return ($calendar ? 'calendars/' : 'addressbooks/').$username.'/'.$uri;
    }

    /**
     * The sharee's copy starts as "<name> (<owner's display name>)" (Decision
     * 17), at most 255 characters. The owner's name is shortened first; when
     * nothing of it is left, the collection name alone is cut to 255.
     */
    public static function shareName(string $collectionName, string $ownerName): string
    {
        $collectionName = trim($collectionName);
        $ownerName = trim($ownerName);

        if ($ownerName === '') {
            return mb_substr($collectionName, 0, self::NAME_LENGTH);
        }

        $room = self::NAME_LENGTH - mb_strlen($collectionName) - mb_strlen(' ()');

        if ($room < 1) {
            return mb_substr($collectionName, 0, self::NAME_LENGTH);
        }

        return $collectionName.' ('.mb_substr($ownerName, 0, $room).')';
    }

    /**
     * The owner's URI, then <uri>-<owner username>, then -2, -3 and so on,
     * the first that is free in the sharee's home. The owner's URI is cut
     * from the end so a suffixed name stays within DavLimits::NAME.
     *
     * @param  array<string, true>  $taken
     */
    public static function freeUri(string $ownerUri, string $ownerUsername, array $taken): string
    {
        if (! isset($taken[$ownerUri])) {
            return $ownerUri;
        }

        for ($n = 1; ; $n++) {
            $suffix = '-'.$ownerUsername.($n === 1 ? '' : '-'.$n);
            $candidate = mb_substr($ownerUri, 0, max(1, DavLimits::NAME - mb_strlen($suffix))).$suffix;

            if (! isset($taken[$candidate])) {
                return $candidate;
            }
        }
    }

    private function shareCalendar(CalendarInstance $collection, User $owner, User $sharee, int $access): CalendarInstance
    {
        $principal = $sharee->principal_uri;

        if (! HomeNamespace::lock($principal)) {
            throw new InvalidArgumentException('That user no longer exists.');
        }

        $source = DB::table('calendarinstances')
            ->where('id', $collection->getKey())
            ->where('access', CalendarInstance::OWNER)
            ->first();

        if ($source === null) {
            throw new InvalidArgumentException('That collection no longer exists.');
        }

        $existing = CalendarInstance::query()
            ->where('calendarid', $source->calendarid)
            ->where('principaluri', $principal)
            ->first();

        if ($existing instanceof CalendarInstance) {
            return $this->updateAccess($existing, $access);
        }

        $id = DB::table('calendarinstances')->insertGetId([
            'calendarid' => $source->calendarid,
            'principaluri' => $principal,
            'access' => $access,
            'displayname' => self::shareName((string) ($source->displayname ?? $source->uri), $owner->display_name),
            'uri' => self::freeUri((string) $source->uri, $owner->username, HomeNamespace::calendarUris($principal)),
            'description' => $source->description,
            'calendarorder' => $source->calendarorder,
            'calendarcolor' => $source->calendarcolor,
            'timezone' => $source->timezone,
            'share_href' => $principal,
            'share_displayname' => mb_substr($sharee->display_name, 0, self::SHAREE_NAME_LENGTH),
            'share_invitestatus' => self::ACCEPTED,
        ]);

        app(PushChanges::class)->homeChanged(true, $principal);

        return CalendarInstance::query()->findOrFail($id);
    }

    private function shareAddressBook(AddressBook $collection, User $owner, User $sharee, int $access): AddressBookInstance
    {
        $principal = $sharee->principal_uri;

        if (! HomeNamespace::lock($principal)) {
            throw new InvalidArgumentException('That user no longer exists.');
        }

        $source = DB::table('addressbooks')->where('id', $collection->getKey())->first();

        if ($source === null) {
            throw new InvalidArgumentException('That collection no longer exists.');
        }

        $existing = AddressBookInstance::query()
            ->where('addressbookid', $source->id)
            ->where('principaluri', $principal)
            ->first();

        if ($existing instanceof AddressBookInstance) {
            return $this->updateAccess($existing, $access);
        }

        $id = DB::table('addressbookinstances')->insertGetId([
            'addressbookid' => $source->id,
            'principaluri' => $principal,
            'uri' => self::freeUri((string) $source->uri, $owner->username, HomeNamespace::addressBookUris($principal)),
            'displayname' => self::shareName((string) ($source->displayname ?? $source->uri), $owner->display_name),
            'description' => $source->description,
            'access' => $access,
            'share_href' => $principal,
            'share_displayname' => mb_substr($sharee->display_name, 0, self::SHAREE_NAME_LENGTH),
            'share_invitestatus' => self::ACCEPTED,
        ]);

        app(PushChanges::class)->homeChanged(false, $principal);

        return AddressBookInstance::query()->findOrFail($id);
    }

    /**
     * @template T of CalendarInstance|AddressBookInstance
     *
     * @param  T  $share
     * @return T
     */
    private function updateAccess(CalendarInstance|AddressBookInstance $share, int $access): CalendarInstance|AddressBookInstance
    {
        if ((int) $share->getAttribute('access') !== $access) {
            $share->newQuery()->whereKey($share->getKey())->update(['access' => $access]);
            $share->setAttribute('access', $access);
        }

        return $share;
    }

    /**
     * The share rows of this collection for this user. The owner's own
     * calendar row (access 1) never matches.
     *
     * @return Builder
     */
    private function shareQuery(CalendarInstance|AddressBook $collection, User $sharee)
    {
        return $collection instanceof CalendarInstance
            ? DB::table('calendarinstances')
                ->where('calendarid', $collection->calendarid)
                ->where('principaluri', $sharee->principal_uri)
                ->whereIn('access', self::SHARED)
            : DB::table('addressbookinstances')
                ->where('addressbookid', $collection->getKey())
                ->where('principaluri', $sharee->principal_uri);
    }

    /**
     * The user who owns the collection. A calendar is passed as the owner's
     * instance; a sharee's instance is not a collection that can be shared.
     */
    private function owner(CalendarInstance|AddressBook $collection): User
    {
        if (! $collection->exists) {
            throw new InvalidArgumentException('That collection no longer exists.');
        }

        if ($collection instanceof CalendarInstance && (int) $collection->getAttribute('access') !== CalendarInstance::OWNER) {
            throw new InvalidArgumentException('Only the owner\'s calendar can be shared.');
        }

        $principal = (string) $collection->getAttribute('principaluri');
        $owner = str_starts_with($principal, 'principals/')
            ? User::query()->where('username', substr($principal, strlen('principals/')))->first()
            : null;

        if (! $owner instanceof User) {
            throw new InvalidArgumentException('That collection has no owner.');
        }

        return $owner;
    }

    private function assertAccess(int $access): void
    {
        if (! in_array($access, self::SHARED, true)) {
            throw new InvalidArgumentException('Access must be read only or read and write.');
        }
    }
}
