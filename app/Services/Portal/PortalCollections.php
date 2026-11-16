<?php

namespace App\Services\Portal;

use App\Models\User;
use App\Services\Dav\AddressBookBackend;
use App\Services\Dav\CalendarBackend;
use App\Services\Portal\Exceptions\NotFound;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\CardDAV\Plugin as CardDavPlugin;

/**
 * The calendars and address books a user sees in the portal, read through
 * the same backends as DAV: owned ones and received shares, with Sharing off
 * leaving the shares out, and the Tasks and Notes switches applied to each
 * calendar's components. CalDAV or CardDAV off hides that type.
 *
 * Every lookup by id checks the user again (Decision 10): another user's id
 * and a missing id are both NotFound.
 */
final class PortalCollections
{
    /**
     * The calendar info key a holiday calendar carries (PR 41 and PR 51:
     * CalendarBackend::HOLIDAY).
     */
    public const HOLIDAY = 'nexusdav-holiday';

    private const COMPONENTS = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';

    /**
     * @return list<CollectionRef>
     */
    public function calendars(User $user): array
    {
        if (! $this->enabled('caldav')) {
            return [];
        }

        $refs = [];

        foreach (app(CalendarBackend::class)->getCalendarsForUser($user->principal_uri) as $info) {
            $components = $info[self::COMPONENTS] ?? null;
            $access = (int) ($info['share-access'] ?? CollectionRef::OWNER);
            $holiday = ($info[self::HOLIDAY] ?? false) === true;

            $refs[] = new CollectionRef(
                type: CollectionRef::CALENDAR,
                id: (int) $info['id'][0],
                instanceId: (int) $info['id'][1],
                principalUri: $user->principal_uri,
                uri: (string) $info['uri'],
                name: $this->text($info['{DAV:}displayname'] ?? null) ?? (string) $info['uri'],
                color: $this->text($info['{http://apple.com/ns/ical/}calendar-color'] ?? null),
                description: $this->text($info['{urn:ietf:params:xml:ns:caldav}calendar-description'] ?? null),
                components: $components instanceof SupportedCalendarComponentSet
                    ? array_values(array_filter($components->getValue(), 'is_string'))
                    : ['VEVENT'],
                access: $access,
                holiday: $holiday,
                readOnlyReasons: self::reasons($access, $holiday),
            );
        }

        return $refs;
    }

    /**
     * @return list<CollectionRef>
     */
    public function addressBooks(User $user): array
    {
        if (! $this->enabled('carddav')) {
            return [];
        }

        $refs = [];

        foreach (app(AddressBookBackend::class)->getAddressBooksForUser($user->principal_uri) as $info) {
            $access = (int) ($info['share-access'] ?? CollectionRef::OWNER);

            $refs[] = new CollectionRef(
                type: CollectionRef::ADDRESS_BOOK,
                id: (int) $info['id'],
                instanceId: isset($info['share-instance-id']) ? (int) $info['share-instance-id'] : null,
                principalUri: $user->principal_uri,
                uri: (string) $info['uri'],
                name: $this->text($info['{DAV:}displayname'] ?? null) ?? (string) $info['uri'],
                color: null,
                description: $this->text($info['{'.CardDavPlugin::NS_CARDDAV.'}addressbook-description'] ?? null),
                components: [],
                access: $access,
                holiday: false,
                readOnlyReasons: self::reasons($access, false),
            );
        }

        return $refs;
    }

    /**
     * @return list<CollectionRef>
     */
    public function all(User $user): array
    {
        return [...$this->calendars($user), ...$this->addressBooks($user)];
    }

    /**
     * The user's calendar or address book with this id, read again now.
     */
    public function find(User $user, string $type, int $id): CollectionRef
    {
        $refs = match ($type) {
            CollectionRef::CALENDAR => $this->calendars($user),
            CollectionRef::ADDRESS_BOOK => $this->addressBooks($user),
            default => [],
        };

        foreach ($refs as $ref) {
            if ($ref->id === $id) {
                return $ref;
            }
        }

        throw new NotFound;
    }

    /**
     * Why the user may not change the collection; empty when they may. The
     * one rule every store and page follows. A switched-off component is not
     * a reason: a calendar always takes events, and the stores refuse a task
     * or note the calendar does not take.
     *
     * @return list<string>
     */
    public function readOnlyReasons(CollectionRef $ref): array
    {
        return self::reasons($ref->access, $ref->holiday);
    }

    /**
     * @return list<string>
     */
    private static function reasons(int $access, bool $holiday): array
    {
        $reasons = [];

        if ($access === CollectionRef::READ) {
            $reasons[] = CollectionRef::REASON_READ_ONLY_SHARE;
        }

        if ($holiday) {
            $reasons[] = CollectionRef::REASON_HOLIDAY;
        }

        return $reasons;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function enabled(string $feature): bool
    {
        return filter_var(config('nexusdav.features.'.$feature, true), FILTER_VALIDATE_BOOLEAN);
    }
}
