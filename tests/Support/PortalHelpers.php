<?php

namespace Tests\Support;

use App\Models\AddressBook;
use App\Models\CalendarInstance;
use App\Models\User;
use App\Services\Dav\CalendarBackend;
use App\Services\Dav\SharingService;
use App\Services\Portal\Index\ItemIndexer;
use App\Services\Portal\PortalCollections;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PDO;
use Throwable;

/**
 * Users, collections, DAV writes and assertions for the portal's data layer
 * tests (PR 50a). Builds on PushHelpers: user(), token(), dav(), event(),
 * card(), subscribe().
 */
trait PortalHelpers
{
    use PushHelpers;

    /**
     * The calendar id (calendars.id) of the user's calendar with this URI.
     */
    private function calendarId(User $user, string $uri = 'default'): int
    {
        return (int) DB::table('calendarinstances')->where('principaluri', $user->principal_uri)->where('uri', $uri)->value('calendarid');
    }

    private function addressBookId(User $user, string $uri = 'default'): int
    {
        return (int) DB::table('addressbooks')->where('principaluri', $user->principal_uri)->where('uri', $uri)->value('id');
    }

    /**
     * Shares the owner's calendar; returns the sharee's URI of it.
     */
    private function shareCalendar(User $owner, User $sharee, int $access, string $uri = 'default'): string
    {
        $instance = CalendarInstance::query()->where('principaluri', $owner->principal_uri)->where('uri', $uri)->firstOrFail();

        return (string) app(SharingService::class)->share($instance, $sharee, $access)->uri;
    }

    private function shareAddressBook(User $owner, User $sharee, int $access, string $uri = 'default'): string
    {
        $book = AddressBook::query()->where('principaluri', $owner->principal_uri)->where('uri', $uri)->firstOrFail();

        return (string) app(SharingService::class)->share($book, $sharee, $access)->uri;
    }

    private function putDav(string $path, User $user, string $token, string $body, array $headers = []): TestResponse
    {
        $type = str_contains($body, 'BEGIN:VCARD') ? 'text/vcard; charset=utf-8' : 'text/calendar; charset=utf-8';

        return $this->dav('PUT', $path, (string) $user->username, $token, ['Content-Type' => $type, ...$headers], $body);
    }

    /**
     * The collection's sync token as a DAV client reads it.
     */
    private function syncToken(string $path, User $user, string $token): string
    {
        $body = $this->body($this->dav('PROPFIND', $path, (string) $user->username, $token, ['Depth' => '0'],
            '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:sync-token/></d:prop></d:propfind>')->assertStatus(207));
        $this->assertSame(1, preg_match('#<d:sync-token>([^<]+)</d:sync-token>#', $body, $match), $body);

        return $match[1];
    }

    /**
     * The members a DAV sync-collection report lists after the given token.
     *
     * @return list<string>
     */
    private function syncedSince(string $path, User $user, string $token, string $syncToken): array
    {
        $report = '<?xml version="1.0" encoding="utf-8"?><d:sync-collection xmlns:d="DAV:"><d:sync-token>'.htmlspecialchars($syncToken).'</d:sync-token>'
            .'<d:sync-level>1</d:sync-level><d:prop><d:getetag/></d:prop></d:sync-collection>';
        $body = $this->body($this->dav('REPORT', $path, (string) $user->username, $token, ['Content-Type' => 'application/xml'], $report)->assertStatus(207));
        preg_match_all('#<d:href>([^<]+)</d:href>#', $body, $matches);

        return array_values(array_map(static fn (string $href): string => basename($href), array_filter($matches[1], static fn (string $href): bool => ! str_ends_with($href, '/'))));
    }

    private function assertPushQueued(string $topic): void
    {
        $this->assertTrue(DB::table('push_outbox')->where('topic', $topic)->exists(), 'No push was queued for '.$topic.'.');
    }

    /**
     * The calendar info carries the holiday key, as PR 51's CalendarBackend
     * sets it for a holiday calendar.
     */
    private function flagHoliday(int $calendarId): void
    {
        $this->app->bind(CalendarBackend::class, fn (): CalendarBackend => new class(DB::connection()->getPdo(), $calendarId) extends CalendarBackend
        {
            public function __construct(PDO $pdo, private int $holiday)
            {
                parent::__construct($pdo);
            }

            public function getCalendarsForUser($principalUri)
            {
                return array_map(function (array $calendar): array {
                    $calendar[PortalCollections::HOLIDAY] = (int) $calendar['id'][0] === $this->holiday;

                    return $calendar;
                }, parent::getCalendarsForUser($principalUri));
            }
        });
    }

    /**
     * Runs the call and returns what it threw, which must be of the class.
     *
     * @template T of Throwable
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function assertRefused(string $class, callable $call): Throwable
    {
        try {
            $call();
        } catch (Throwable $exception) {
            $this->assertInstanceOf($class, $exception, $exception->getMessage());

            return $exception;
        }

        $this->fail('Expected '.$class.'.');
    }

    /**
     * The item_index row of a calendar object or card, as an array.
     *
     * @return array<string, mixed>|null
     */
    private function indexRow(bool $calendar, int $collectionId, string $uri): ?array
    {
        $row = DB::table('item_index')->where($calendar ? 'calendar_id' : 'addressbook_id', $collectionId)->where('uri', $uri)->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * Asserts the row exists, is current (indexer version, ETag of the DAV
     * row) and has the given fields.
     *
     * @param  array<string, mixed>  $fields
     */
    private function assertIndexed(bool $calendar, int $collectionId, string $uri, array $fields = []): void
    {
        $row = $this->indexRow($calendar, $collectionId, $uri);
        $this->assertNotNull($row, 'No index row for '.$uri.'.');
        $etag = DB::table($calendar ? 'calendarobjects' : 'cards')->where($calendar ? 'calendarid' : 'addressbookid', $collectionId)->where('uri', $uri)->value('etag');
        $this->assertSame((string) $etag, $row['etag'], 'The index row of '.$uri.' is not current.');
        $this->assertSame(ItemIndexer::VERSION, (int) $row['indexer_version']);

        foreach ($fields as $name => $value) {
            $actual = $row[$name];

            if (is_bool($value)) {
                $actual = (bool) $actual;
            } elseif (is_string($actual) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $actual) === 1) {
                $actual = substr($actual, 0, 19);
            }

            $this->assertSame($value, $actual, 'Index field '.$name.' of '.$uri.'.');
        }
    }

    private function assertNotIndexed(bool $calendar, int $collectionId, string $uri): void
    {
        $this->assertNull($this->indexRow($calendar, $collectionId, $uri), 'An index row is left for '.$uri.'.');
    }

    /**
     * Inserts events straight into calendarobjects, past the backend and its
     * index hook, as a calendar from before the index would be.
     */
    private function bulkEvents(int $calendarId, int $count, string $prefix = 'bulk'): void
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $data = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VEVENT\r\nUID:$prefix$i\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260601T100000Z\r\nSUMMARY:Bulk $i\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
            $rows[] = ['calendarid' => $calendarId, 'uri' => "$prefix$i.ics", 'calendardata' => $data, 'etag' => md5($data), 'size' => strlen($data), 'lastmodified' => time(), 'componenttype' => 'VEVENT', 'uid' => "$prefix$i", 'firstoccurence' => 0, 'lastoccurence' => 0];

            if (count($rows) === 200) {
                DB::table('calendarobjects')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('calendarobjects')->insert($rows);
        }
    }

    /**
     * An event every day at 09:00 in Berlin across the change to summer
     * time (Sunday, March 29, 2026), with March 29 deleted and March 30
     * moved to 12:00.
     */
    private function dstSeries(string $uid = 'dst'): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\n"
            ."BEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\nBEGIN:STANDARD\r\nDTSTART:19701025T030000\r\nRRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\nEND:STANDARD\r\n"
            ."BEGIN:DAYLIGHT\r\nDTSTART:19700329T020000\r\nRRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU\r\nTZOFFSETFROM:+0100\r\nTZOFFSETTO:+0200\r\nEND:DAYLIGHT\r\nEND:VTIMEZONE\r\n"
            ."BEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20260101T000000Z\r\nDTSTART;TZID=Europe/Berlin:20260327T090000\r\nDTEND;TZID=Europe/Berlin:20260327T100000\r\n"
            ."RRULE:FREQ=DAILY;COUNT=5\r\nEXDATE;TZID=Europe/Berlin:20260329T090000\r\nSUMMARY:Stand-up\r\nEND:VEVENT\r\n"
            ."BEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20260101T000000Z\r\nRECURRENCE-ID;TZID=Europe/Berlin:20260330T090000\r\nDTSTART;TZID=Europe/Berlin:20260330T120000\r\n"
            ."DTEND;TZID=Europe/Berlin:20260330T130000\r\nSUMMARY:Stand-up (moved)\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    private function task(string $uid, string $summary = 'Task'): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//NexusDAV//test//EN\r\nBEGIN:VTODO\r\nUID:$uid\r\nDTSTAMP:20260101T000000Z\r\nSUMMARY:$summary\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
    }
}
