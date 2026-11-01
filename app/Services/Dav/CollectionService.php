<?php

namespace App\Services\Dav;

use App\Models\AddressBook;
use App\Models\CalendarInstance;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Admin create, edit, and delete for calendars and address books.
 * Writes go through the sabre backends. Deletes also clear property storage
 * and locks, the same paths DavDataCleaner uses for a user.
 */
final class CollectionService
{
    public const SLUG_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,199}$/';

    public const COLOR_PATTERN = '/^#[0-9A-Fa-f]{6}$/';

    public function createCalendar(User $user, string $uri, string $displayName, ?string $description, string $color, bool $tasks, bool $notes): void
    {
        $uri = $this->calendarUri($user, $uri);
        $displayName = $this->displayName($displayName);
        $description = $this->description($description);
        $color = $this->color($color);
        $components = ['VEVENT'];

        if ($tasks) {
            $components[] = 'VTODO';
        }

        if ($notes) {
            $components[] = 'VJOURNAL';
        }

        DB::transaction(function () use ($user, $uri, $displayName, $description, $color, $components): void {
            $this->calendars()->createCollection(
                $user->principal_uri,
                $uri,
                $displayName,
                $description,
                $color,
                $components,
            );
        });
    }

    public function updateCalendar(User $user, CalendarInstance $instance, string $displayName, ?string $description, string $color, bool $tasks, bool $notes): void
    {
        $this->assertOwns($instance->principaluri, $user);
        $displayName = $this->displayName($displayName);
        $description = $this->description($description);
        $color = $this->color($color);
        $components = $this->editedComponents((int) $instance->calendarid, $tasks, $notes);
        $calendarId = (int) $instance->calendarid;
        $instanceId = (int) $instance->getKey();

        DB::transaction(function () use ($calendarId, $instanceId, $displayName, $description, $color, $components): void {
            $this->calendars()->updateCollection($calendarId, $instanceId, $displayName, $description, $color);
            $this->calendars()->setComponents($calendarId, $components);
        });
    }

    public function deleteCalendar(User $user, CalendarInstance $instance): void
    {
        $this->assertOwns($instance->principaluri, $user);
        $uri = (string) $instance->uri;
        $calendarId = (int) $instance->calendarid;
        $instanceId = (int) $instance->getKey();

        DB::transaction(function () use ($user, $uri, $calendarId, $instanceId): void {
            $this->calendars()->deleteCalendar([$calendarId, $instanceId]);
            app(DavDataCleaner::class)->deletePath('calendars/'.$user->username.'/'.$uri);
        });
    }

    public function createAddressBook(User $user, string $uri, string $displayName, ?string $description): void
    {
        $uri = $this->addressBookUri($user, $uri);
        $displayName = $this->displayName($displayName);
        $description = $this->description($description);

        DB::transaction(function () use ($user, $uri, $displayName, $description): void {
            $this->books()->createCollection($user->principal_uri, $uri, $displayName, $description);
        });
    }

    public function updateAddressBook(User $user, AddressBook $book, string $displayName, ?string $description): void
    {
        $this->assertOwns($book->principaluri, $user);
        $displayName = $this->displayName($displayName);
        $description = $this->description($description);
        $id = (int) $book->getKey();

        DB::transaction(function () use ($id, $displayName, $description): void {
            $this->books()->updateCollection($id, $displayName, $description);
        });
    }

    public function deleteAddressBook(User $user, AddressBook $book): void
    {
        $this->assertOwns($book->principaluri, $user);
        $uri = (string) $book->uri;
        $id = (int) $book->getKey();

        DB::transaction(function () use ($user, $uri, $id): void {
            $this->books()->deleteAddressBook($id);
            app(DavDataCleaner::class)->deletePath('addressbooks/'.$user->username.'/'.$uri);
        });
    }

    public function suggestedCalendarUri(User $user): string
    {
        return $this->suggestedUri($user, 'calendarinstances', UserService::DEFAULT_CALENDAR_URI, 'calendar');
    }

    public function suggestedAddressBookUri(User $user): string
    {
        return $this->suggestedUri($user, 'addressbooks', UserService::DEFAULT_ADDRESS_BOOK_URI, 'contacts');
    }

    public function calendarUriTaken(User $user, string $uri): bool
    {
        return DB::table('calendarinstances')
            ->where('principaluri', $user->principal_uri)
            ->where('uri', $uri)
            ->exists();
    }

    public function addressBookUriTaken(User $user, string $uri): bool
    {
        return DB::table('addressbooks')
            ->where('principaluri', $user->principal_uri)
            ->where('uri', $uri)
            ->exists();
    }

    /**
     * @return list<string>
     */
    public function allowedComponents(): array
    {
        return $this->calendars()->allowedComponents();
    }

    private function calendarUri(User $user, string $uri): string
    {
        $uri = $this->slug($uri);

        if (in_array($uri, CalendarHome::RESERVED_NAMES, true)) {
            throw new InvalidArgumentException('This name is reserved.');
        }

        if ($this->calendarUriTaken($user, $uri)) {
            throw new InvalidArgumentException('That URI is already used.');
        }

        return $uri;
    }

    private function addressBookUri(User $user, string $uri): string
    {
        $uri = $this->slug($uri);

        if ($this->addressBookUriTaken($user, $uri)) {
            throw new InvalidArgumentException('That URI is already used.');
        }

        return $uri;
    }

    private function slug(string $uri): string
    {
        $uri = trim($uri);

        if (preg_match(self::SLUG_PATTERN, $uri) !== 1) {
            throw new InvalidArgumentException('The URI must be lowercase and URL-safe.');
        }

        return $uri;
    }

    private function displayName(string $displayName): string
    {
        $displayName = trim($displayName);

        if ($displayName === '') {
            throw new InvalidArgumentException('Display name is required.');
        }

        if (mb_strlen($displayName) > 255) {
            throw new InvalidArgumentException('Display name is too long.');
        }

        return $displayName;
    }

    private function description(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        $description = trim($description);

        return $description === '' ? null : $description;
    }

    private function color(string $color): string
    {
        $color = trim($color);

        if (preg_match(self::COLOR_PATTERN, $color) !== 1) {
            throw new InvalidArgumentException('Color must be #RRGGBB.');
        }

        return strtoupper($color);
    }

    /**
     * A feature that is off is left as stored. New calendars do not reach
     * this path. createCollection() drops disabled components in the backend.
     *
     * @return list<string>
     */
    private function editedComponents(int $calendarId, bool $tasks, bool $notes): array
    {
        $stored = DB::table('calendars')->where('id', $calendarId)->value('components');
        $components = is_string($stored) && $stored !== '' ? explode(',', $stored) : [];
        $allowed = $this->allowedComponents();

        if (in_array('VTODO', $allowed, true)) {
            $components = $this->toggle($components, 'VTODO', $tasks);
        }

        if (in_array('VJOURNAL', $allowed, true)) {
            $components = $this->toggle($components, 'VJOURNAL', $notes);
        }

        if (! in_array('VEVENT', $components, true)) {
            array_unshift($components, 'VEVENT');
        }

        return $components;
    }

    /**
     * @param  list<string>  $components
     * @return list<string>
     */
    private function toggle(array $components, string $name, bool $on): array
    {
        $components = array_values(array_filter(
            $components,
            fn (string $component): bool => $component !== $name,
        ));

        if ($on) {
            $components[] = $name;
        }

        return $components;
    }

    private function suggestedUri(User $user, string $table, string $preferred, string $prefix): string
    {
        $taken = array_fill_keys(
            DB::table($table)->where('principaluri', $user->principal_uri)->pluck('uri')->all(),
            true,
        );

        if (! isset($taken[$preferred])) {
            return $preferred;
        }

        if (! isset($taken[$prefix])) {
            return $prefix;
        }

        // A slug is at most 200 characters. Past that, leave the field empty.
        for ($i = 2; ; $i++) {
            $candidate = $prefix.'-'.$i;

            if (preg_match(self::SLUG_PATTERN, $candidate) !== 1) {
                return '';
            }

            if (! isset($taken[$candidate])) {
                return $candidate;
            }
        }
    }

    private function assertOwns(mixed $principalUri, User $user): void
    {
        if ($principalUri !== $user->principal_uri) {
            throw new InvalidArgumentException('That collection belongs to another user.');
        }
    }

    private function calendars(): CalendarBackend
    {
        return new CalendarBackend(DB::connection()->getPdo());
    }

    private function books(): AddressBookBackend
    {
        return new AddressBookBackend(DB::connection()->getPdo());
    }
}
