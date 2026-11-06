<?php

namespace App\Services\Dav;

use Illuminate\Support\Facades\DB;

/**
 * The names in one principal's calendar home and address book home.
 *
 * Each home is filled from two tables with separate unique keys: the calendar
 * home from calendarinstances (owned calendars and received shares) and
 * calendarsubscriptions, the address book home from addressbooks and
 * addressbookinstances (received shares). A name is free only when neither
 * table has it, or the home would list two children of one name.
 *
 * Writers that pick a name call lock() inside their transaction before they
 * read. It updates the principal's row to itself: a row lock on PostgreSQL,
 * and a write on SQLite, whose transactions already start with the database
 * write lock. SELECT ... FOR UPDATE is not used because SQLite drops it.
 * Deleting a user takes the same lock, so a share cannot be written into a
 * home that is being deleted.
 */
final class HomeNamespace
{
    /**
     * Returns false when the principal no longer exists: its user was
     * deleted, and nothing may be written into that home.
     */
    public static function lock(string $principalUri): bool
    {
        return DB::table('principals')->where('uri', $principalUri)->update(['uri' => $principalUri]) > 0;
    }

    public static function calendarUriTaken(string $principalUri, string $uri): bool
    {
        return in_array($uri, CalendarHome::RESERVED_NAMES, true)
            || DB::table('calendarinstances')->where('principaluri', $principalUri)->where('uri', $uri)->exists()
            || DB::table('calendarsubscriptions')->where('principaluri', $principalUri)->where('uri', $uri)->exists();
    }

    public static function addressBookUriTaken(string $principalUri, string $uri): bool
    {
        return DB::table('addressbooks')->where('principaluri', $principalUri)->where('uri', $uri)->exists()
            || DB::table('addressbookinstances')->where('principaluri', $principalUri)->where('uri', $uri)->exists();
    }

    /**
     * Every name the calendar home uses, including the reserved ones.
     *
     * @return array<string, true>
     */
    public static function calendarUris(string $principalUri): array
    {
        return self::names([
            ...CalendarHome::RESERVED_NAMES,
            ...DB::table('calendarinstances')->where('principaluri', $principalUri)->pluck('uri')->all(),
            ...DB::table('calendarsubscriptions')->where('principaluri', $principalUri)->pluck('uri')->all(),
        ]);
    }

    /**
     * @return array<string, true>
     */
    public static function addressBookUris(string $principalUri): array
    {
        return self::names([
            ...DB::table('addressbooks')->where('principaluri', $principalUri)->pluck('uri')->all(),
            ...DB::table('addressbookinstances')->where('principaluri', $principalUri)->pluck('uri')->all(),
        ]);
    }

    /**
     * @param  array<int, mixed>  $uris
     * @return array<string, true>
     */
    private static function names(array $uris): array
    {
        $names = [];

        foreach ($uris as $uri) {
            $names[(string) $uri] = true;
        }

        return $names;
    }
}
