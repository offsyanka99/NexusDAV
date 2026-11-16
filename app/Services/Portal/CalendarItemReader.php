<?php

namespace App\Services\Portal;

use App\Models\User;
use App\Services\Dav\BinaryValue;
use App\Services\Portal\Exceptions\NotFound;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Events, tasks and notes of a calendar the user sees, read with the same
 * access check as the stores: another user's calendar or a missing item is
 * NotFound.
 */
final class CalendarItemReader
{
    public function __construct(private PortalCollections $collections) {}

    public function get(User $user, int $calendarId, string $uri): StoredItem
    {
        $ref = $this->collections->find($user, CollectionRef::CALENDAR, $calendarId);
        $row = DB::table('calendarobjects')->where('calendarid', $ref->id)->where('uri', $uri)->first(['uri', 'etag', 'calendardata']);

        if ($row === null) {
            throw new NotFound;
        }

        return new StoredItem($ref, (string) $row->uri, '"'.$row->etag.'"', BinaryValue::toString($row->calendardata));
    }

    /**
     * Every object of the calendar, read in chunks by id, so a large
     * calendar is never in memory at once (export). The access check runs
     * now; the rows are read as the cursor is walked.
     *
     * @return LazyCollection<int, StoredItem>
     */
    public function all(User $user, int $calendarId, int $chunk = 200): LazyCollection
    {
        $ref = $this->collections->find($user, CollectionRef::CALENDAR, $calendarId);

        return DB::table('calendarobjects')
            ->where('calendarid', $ref->id)
            ->select(['id', 'uri', 'etag', 'calendardata'])
            ->lazyById($chunk)
            ->map(static fn (object $row): StoredItem => new StoredItem($ref, (string) $row->uri, '"'.$row->etag.'"', BinaryValue::toString($row->calendardata)));
    }
}
