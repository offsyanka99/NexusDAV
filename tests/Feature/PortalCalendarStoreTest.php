<?php

namespace Tests\Feature;

use App\Jobs\SendPushMessages;
use App\Models\User;
use App\Services\Dav\SharingService;
use App\Services\Portal\BatchItem;
use App\Services\Portal\BatchResult;
use App\Services\Portal\CalendarItemReader;
use App\Services\Portal\CalendarItemStore;
use App\Services\Portal\Exceptions\AlreadyExists;
use App\Services\Portal\Exceptions\ChangedElsewhere;
use App\Services\Portal\Exceptions\InvalidItem;
use App\Services\Portal\Exceptions\NotFound;
use App\Services\Portal\Exceptions\ReadOnlyCollection;
use App\Services\Portal\ItemEditor;
use App\Services\Portal\ItemStore;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Sabre\VObject\Component\VCalendar;
use Tests\Support\PortalHelpers;
use Tests\TestCase;

/**
 * PR 50a, steps 4 to 10: portal writes of events, tasks and notes behave
 * like a CalDAV client's writes, and the reader returns what is stored.
 */
class PortalCalendarStoreTest extends TestCase
{
    use PortalHelpers, RefreshDatabase;

    private const CALENDAR = '/dav/calendars/ada/default/';

    private User $ada;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config(['nexusdav.features.push' => true, 'app.url' => 'https://dav.example.org']);
        $this->fakeDelivery();
        $this->ada = $this->user('ada');
        $this->token = $this->token($this->ada);
    }

    public function test_a_store_write_moves_the_sync_token_queues_push_and_a_dav_client_syncs_it(): void
    {
        $subscription = $this->subscribe(self::CALENDAR, 'ada', $this->token);
        $before = $this->syncToken(self::CALENDAR, $this->ada, $this->token);
        Queue::fake();

        $created = $this->store()->create($this->ada, $this->calendarId($this->ada), 'lunch.ics', $this->event('lunch', 'Lunch'));

        $this->assertSame('lunch.ics', $created->uri);
        $this->assertSame('lunch', $created->uid);
        $this->assertNotSame($before, $this->syncToken(self::CALENDAR, $this->ada, $this->token));
        $this->assertPushQueued($subscription->topic);
        $this->assertSame(['lunch.ics'], $this->syncedSince(self::CALENDAR, $this->ada, $this->token, $before));

        $get = $this->dav('GET', self::CALENDAR.'lunch.ics', 'ada', $this->token)->assertOk();
        $this->assertSame($created->etag, $get->headers->get('ETag'));

        $mid = $this->syncToken(self::CALENDAR, $this->ada, $this->token);
        $updated = $this->store()->update($this->ada, $this->calendarId($this->ada), 'lunch.ics', $this->event('lunch', 'Long lunch'), $created->etag);
        $this->assertNotSame($created->etag, $updated->etag);
        $this->assertSame(['lunch.ics'], $this->syncedSince(self::CALENDAR, $this->ada, $this->token, $mid));
        $this->assertStringContainsString('SUMMARY:Long lunch', $this->body($this->dav('GET', self::CALENDAR.'lunch.ics', 'ada', $this->token)));

        $this->store()->delete($this->ada, $this->calendarId($this->ada), 'lunch.ics', $updated->etag);
        $this->dav('GET', self::CALENDAR.'lunch.ics', 'ada', $this->token)->assertNotFound();
    }

    public function test_a_stale_etag_is_refused_after_a_dav_client_changed_the_item(): void
    {
        $created = $this->store()->create($this->ada, $this->calendarId($this->ada), 'a.ics', $this->event('a', 'First'));
        $this->putDav(self::CALENDAR.'a.ics', $this->ada, $this->token, $this->event('a', 'Phone'))->assertNoContent();

        $this->assertRefused(ChangedElsewhere::class, fn () => $this->store()->update($this->ada, $this->calendarId($this->ada), 'a.ics', $this->event('a', 'Portal'), $created->etag));
        $this->assertRefused(ChangedElsewhere::class, fn () => $this->store()->delete($this->ada, $this->calendarId($this->ada), 'a.ics', $created->etag));
        $this->assertStringContainsString('SUMMARY:Phone', $this->body($this->dav('GET', self::CALENDAR.'a.ics', 'ada', $this->token)));

        $current = app(CalendarItemReader::class)->get($this->ada, $this->calendarId($this->ada), 'a.ics');
        $this->store()->update($this->ada, $this->calendarId($this->ada), 'a.ics', $this->event('a', 'Portal'), $current->etag);

        $this->assertRefused(NotFound::class, fn () => $this->store()->update($this->ada, $this->calendarId($this->ada), 'gone.ics', $this->event('gone'), '"x"'));
    }

    public function test_read_only_shares_holiday_calendars_and_other_users_calendars_are_refused(): void
    {
        $bob = $this->user('bob');
        $cleo = $this->user('cleo');
        $this->shareCalendar($this->ada, $bob, SharingService::READ);
        $this->shareCalendar($this->ada, $cleo, SharingService::READ_WRITE);
        $calendar = $this->calendarId($this->ada);

        $readOnly = $this->assertRefused(ReadOnlyCollection::class, fn () => $this->store()->create($bob, $calendar, 'b.ics', $this->event('b')));
        $this->assertSame('This calendar is read-only.', $readOnly->title());
        $this->assertRefused(NotFound::class, fn () => $this->store()->create($bob, $this->calendarId($cleo), 'b.ics', $this->event('b')));

        $written = $this->store()->create($cleo, $calendar, 'c.ics', $this->event('c'));
        $this->assertSame('c.ics', $written->uri);
        $this->assertRefused(ReadOnlyCollection::class, fn () => $this->store()->delete($bob, $calendar, 'c.ics', $written->etag));

        $this->flagHoliday($calendar);
        $holiday = $this->assertRefused(ReadOnlyCollection::class, fn () => $this->store()->create($this->ada, $calendar, 'h.ics', $this->event('h')));
        $this->assertSame('Holiday calendars are filled by the server.', $holiday->body());
        $this->assertRefused(ReadOnlyCollection::class, fn () => $this->store()->batch($this->ada, $calendar, [$this->event('h')]));
        $this->assertSame(1, DB::table('calendarobjects')->where('calendarid', $calendar)->count());
    }

    public function test_a_task_is_refused_while_tasks_are_off_and_an_event_in_the_same_calendar_is_written(): void
    {
        $calendar = $this->calendarId($this->ada);
        $task = $this->store()->create($this->ada, $calendar, 'task.ics', $this->task('task'));
        config(['nexusdav.features.tasks' => false]);

        $refused = $this->assertRefused(InvalidItem::class, fn () => $this->store()->create($this->ada, $calendar, 'new-task.ics', $this->task('new-task')));
        $this->assertSame('This calendar does not take tasks.', $refused->getMessage());
        $this->assertRefused(InvalidItem::class, fn () => $this->store()->update($this->ada, $calendar, 'task.ics', $this->task('task', 'Changed'), $task->etag));

        $this->assertSame('event.ics', $this->store()->create($this->ada, $calendar, 'event.ics', $this->event('event'))->uri);

        // As on DAV, an existing task can still be deleted.
        $this->store()->delete($this->ada, $calendar, 'task.ics', $task->etag);
        $this->assertFalse(DB::table('calendarobjects')->where('uri', 'task.ics')->exists());
    }

    public function test_invalid_data_is_refused_with_its_message_and_a_repairable_object_is_repaired(): void
    {
        $calendar = $this->calendarId($this->ada);

        $parse = $this->assertRefused(InvalidItem::class, fn () => $this->store()->create($this->ada, $calendar, 'x.ics', 'not a calendar'));
        $this->assertStringStartsWith('This resource only supports valid iCalendar 2.0 data. Parse error:', $parse->getMessage());
        $this->assertSame($parse->getMessage(), $parse->body());

        $card = $this->assertRefused(InvalidItem::class, fn () => $this->store()->create($this->ada, $calendar, 'x.ics', $this->card('x')));
        $this->assertSame('This collection can only support iCalendar objects.', $card->getMessage());

        $mixed = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VEVENT\r\nUID:m\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260101T100000Z\r\nEND:VEVENT\r\nBEGIN:VTODO\r\nUID:m\r\nDTSTAMP:20260101T000000Z\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
        $this->assertRefused(InvalidItem::class, fn () => $this->store()->create($this->ada, $calendar, 'm.ics', $mixed));

        $twoUids = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VEVENT\r\nUID:one\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260101T100000Z\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:two\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260102T100000Z\r\nRECURRENCE-ID:20260102T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $this->assertSame('Validation error in iCalendar: A calendar object on a CalDAV server may only have components with the same UID.', $this->assertRefused(InvalidItem::class, fn () => $this->store()->create($this->ada, $calendar, 'u.ics', $twoUids))->getMessage());

        $longUri = str_repeat('a', 201).'.ics';
        $this->assertRefused(InvalidItem::class, fn () => $this->store()->create($this->ada, $calendar, $longUri, $this->event('long')));
        $this->assertRefused(InvalidItem::class, fn () => $this->store()->create($this->ada, $calendar, '../x.ics', $this->event('dots')));
        $this->assertRefused(InvalidItem::class, fn () => $this->store()->create($this->ada, $calendar, 'uid.ics', $this->event(str_repeat('u', 201))));

        // No UID: repaired with one, as sabre repairs a client's PUT.
        $noUid = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VEVENT\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260101T100000Z\r\nSUMMARY:Repaired\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $repaired = $this->store()->create($this->ada, $calendar, 'repaired.ics', $noUid);
        $this->assertNotSame('', $repaired->uid);
        $this->assertStringContainsString('UID:'.$repaired->uid, $this->body($this->dav('GET', self::CALENDAR.'repaired.ics', 'ada', $this->token)));

        $this->assertSame(1, DB::table('calendarobjects')->where('calendarid', $calendar)->count());
    }

    public function test_uris_and_uids_cannot_be_taken_twice_and_the_uid_cannot_change(): void
    {
        $calendar = $this->calendarId($this->ada);
        $first = $this->store()->create($this->ada, $calendar, 'a.ics', $this->event('same'));

        $this->assertRefused(AlreadyExists::class, fn () => $this->store()->create($this->ada, $calendar, 'a.ics', $this->event('other')));
        $uid = $this->assertRefused(AlreadyExists::class, fn () => $this->store()->create($this->ada, $calendar, 'b.ics', $this->event('same')));
        $this->assertSame('Another item in this collection has the same UID.', $uid->getMessage());
        $this->assertSame('The UID of an item cannot be changed.', $this->assertRefused(InvalidItem::class, fn () => $this->store()->update($this->ada, $calendar, 'a.ics', $this->event('new-uid'), $first->etag))->getMessage());
    }

    public function test_a_form_create_mints_a_uuid_and_the_editor_keeps_what_the_form_does_not_edit(): void
    {
        $calendar = $this->calendarId($this->ada);
        $vcalendar = new VCalendar;
        $vcalendar->add('VEVENT', ['SUMMARY' => 'From the form', 'DTSTART' => new \DateTimeImmutable('2026-12-01 10:00:00', new \DateTimeZone('UTC'))]);

        $created = $this->store()->createFromForm($this->ada, $calendar, $vcalendar);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $created->uid);
        $this->assertSame($created->uid.'.ics', $created->uri);

        // A client stored alarms, attendees and a custom property.
        $rich = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Phone//EN\r\nBEGIN:VEVENT\r\nUID:rich\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20261201T100000Z\r\nSUMMARY:Old\r\n"
            ."ATTENDEE;CN=Bob;PARTSTAT=ACCEPTED:mailto:bob@example.com\r\nX-CUSTOM;X-PARAM=1:keep me\r\nBEGIN:VALARM\r\nACTION:DISPLAY\r\nDESCRIPTION:Soon\r\nTRIGGER:-PT15M\r\nEND:VALARM\r\nEND:VEVENT\r\n"
            ."BEGIN:VEVENT\r\nUID:rich\r\nDTSTAMP:20260101T000000Z\r\nRECURRENCE-ID:20261208T100000Z\r\nDTSTART:20261208T110000Z\r\nSUMMARY:Override\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $this->putDav(self::CALENDAR.'rich.ics', $this->ada, $this->token, $rich)->assertCreated();

        $stored = app(CalendarItemReader::class)->get($this->ada, $calendar, 'rich.ics');
        $editor = ItemEditor::forCalendar($stored->calendar());
        $this->assertSame('Old', $editor->value('SUMMARY'));
        $editor->set('SUMMARY', 'New')->set('LOCATION', 'Room 1')->remove('DESCRIPTION')->touch();
        $this->store()->update($this->ada, $calendar, 'rich.ics', $editor->serialize(), $stored->etag);

        $text = app(CalendarItemReader::class)->get($this->ada, $calendar, 'rich.ics')->data;
        $this->assertStringContainsString('SUMMARY:New', $text);
        $this->assertStringContainsString('LOCATION:Room 1', $text);
        $this->assertStringContainsString('ATTENDEE;CN=Bob;PARTSTAT=ACCEPTED:mailto:bob@example.com', $text);
        $this->assertStringContainsString('X-CUSTOM;X-PARAM=1:keep me', $text);
        $this->assertStringContainsString('TRIGGER:-PT15M', $text);
        $this->assertStringContainsString('SUMMARY:Override', $text);
        $this->assertStringContainsString('SEQUENCE:1', $text);
    }

    public function test_a_batch_takes_one_lock_queues_one_push_and_refused_items_do_not_stop_the_others(): void
    {
        $subscription = $this->subscribe(self::CALENDAR, 'ada', $this->token);
        $calendar = $this->calendarId($this->ada);
        $this->store()->create($this->ada, $calendar, 'kept.ics', $this->event('kept', 'Stored'));
        DB::table('push_outbox')->delete();
        Queue::fake();

        // Transactions that begin before the batch's objects are in: the
        // write's own. PushQueue merges after the commit, in its own.
        $writeTransactions = 0;
        $locks = 0;
        Event::listen(TransactionBeginning::class, function () use (&$writeTransactions, $calendar): void {
            $writeTransactions += DB::table('calendarobjects')->where('calendarid', $calendar)->count() === 1 ? 1 : 0;
        });
        DB::listen(function ($query) use (&$locks): void {
            $locks += str_contains($query->sql, 'pg_advisory_xact_lock') ? 1 : 0;
        });

        $result = $this->store()->batch($this->ada, $calendar, [
            $this->event('one'),
            'not a calendar',
            new BatchItem($this->event('two'), 'chosen.ics'),
            $this->event('kept', 'Imported'),
            $this->event('three'),
        ]);

        $this->assertSame(1, $writeTransactions);
        $this->assertSame(DB::connection()->getDriverName() === 'pgsql' ? 1 : 0, $locks);
        $this->assertSame([BatchResult::CREATED, BatchResult::REFUSED, BatchResult::CREATED, BatchResult::SKIPPED, BatchResult::CREATED], array_column($result->items(), 'status'));
        $this->assertSame(['one.ics', null, 'chosen.ics', 'kept.ics', 'three.ics'], array_column($result->items(), 'uri'));
        $this->assertInstanceOf(InvalidItem::class, $result->refused()[0]['error']);
        $this->assertSame(3, $result->count(BatchResult::CREATED));
        $this->assertSame(1, DB::table('push_outbox')->where('topic', $subscription->topic)->count());
        Queue::assertPushed(SendPushMessages::class, 1);

        $replace = $this->store()->batch($this->ada, $calendar, [$this->event('kept', 'Imported'), $this->event('one', 'Again')], ItemStore::REPLACE);
        $this->assertSame([BatchResult::UPDATED, BatchResult::UPDATED], array_column($replace->items(), 'status'));
        $this->assertStringContainsString('SUMMARY:Imported', app(CalendarItemReader::class)->get($this->ada, $calendar, 'kept.ics')->data);

        $fail = $this->store()->batch($this->ada, $calendar, [$this->event('kept'), $this->event('four')], ItemStore::FAIL);
        $this->assertSame([BatchResult::REFUSED, BatchResult::CREATED], array_column($fail->items(), 'status'));
        $this->assertInstanceOf(AlreadyExists::class, $fail->refused()[0]['error']);

        $stale = $this->store()->batch($this->ada, $calendar, [new BatchItem($this->event('kept', 'Stale'), null, '"old"')], ItemStore::REPLACE);
        $this->assertInstanceOf(ChangedElsewhere::class, $stale->refused()[0]['error']);
    }

    public function test_a_batch_never_writes_over_a_stored_uri_and_refuses_a_series_without_instances(): void
    {
        $calendar = $this->calendarId($this->ada);
        // A DAV client may store two objects with one UID; sabre does not refuse it.
        $this->putDav(self::CALENDAR.'x.ics', $this->ada, $this->token, $this->event('dup'))->assertCreated();
        $this->putDav(self::CALENDAR.'y.ics', $this->ada, $this->token, $this->event('dup'))->assertCreated();
        $empty = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VEVENT\r\nUID:empty\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260105T100000Z\r\n"
            ."RRULE:FREQ=DAILY;COUNT=1\r\nEXDATE:20260105T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        $result = $this->store()->batch($this->ada, $calendar, [
            $this->event('ok1'),
            $this->event('y'),
            new BatchItem($this->event('chosen'), 'y.ics'),
            $empty,
            $this->event('ok2'),
        ]);

        $this->assertSame([BatchResult::CREATED, BatchResult::CREATED, BatchResult::REFUSED, BatchResult::REFUSED, BatchResult::CREATED], array_column($result->items(), 'status'));
        $this->assertNotSame('y.ics', $result->items()[1]['uri']);
        $this->assertInstanceOf(AlreadyExists::class, $result->items()[2]['error']);
        $this->assertInstanceOf(InvalidItem::class, $result->items()[3]['error']);
        $this->assertSame(5, DB::table('calendarobjects')->where('calendarid', $calendar)->count());

        // Alone, the same series is refused, not a server error.
        $this->assertRefused(InvalidItem::class, fn () => $this->store()->create($this->ada, $calendar, 'empty.ics', $empty));
    }

    public function test_the_reader_returns_the_stored_data_and_etag_and_refuses_another_users_item(): void
    {
        $bob = $this->user('bob');
        $calendar = $this->calendarId($this->ada);
        $this->putDav(self::CALENDAR.'a.ics', $this->ada, $this->token, $this->event('a', 'Stored'))->assertCreated();
        $etag = $this->dav('GET', self::CALENDAR.'a.ics', 'ada', $this->token)->headers->get('ETag');

        $item = app(CalendarItemReader::class)->get($this->ada, $calendar, 'a.ics');
        $this->assertSame($etag, $item->etag);
        $this->assertSame($this->event('a', 'Stored'), $item->data);
        $this->assertSame('Stored', (string) $item->calendar()->VEVENT->SUMMARY);
        $this->assertSame($calendar, $item->collection->id);

        $this->assertRefused(NotFound::class, fn () => app(CalendarItemReader::class)->get($bob, $calendar, 'a.ics'));
        $this->assertRefused(NotFound::class, fn () => app(CalendarItemReader::class)->all($bob, $calendar));
        $this->assertRefused(NotFound::class, fn () => app(CalendarItemReader::class)->get($this->ada, $calendar, 'missing.ics'));

        // A read-only sharee reads it.
        $this->shareCalendar($this->ada, $bob, SharingService::READ);
        $this->assertSame($etag, app(CalendarItemReader::class)->get($bob, $calendar, 'a.ics')->etag);
    }

    public function test_the_cursor_reads_every_object_without_holding_the_calendar_in_memory(): void
    {
        $calendar = $this->calendarId($this->ada);
        $padding = str_repeat('x', 4000);
        $rows = [];

        for ($i = 0; $i < 600; $i++) {
            $data = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VEVENT\r\nUID:e$i\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260101T100000Z\r\nDESCRIPTION:$padding\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
            $rows[] = ['calendarid' => $calendar, 'uri' => "e$i.ics", 'calendardata' => $data, 'etag' => md5($data), 'size' => strlen($data), 'lastmodified' => time(), 'componenttype' => 'VEVENT', 'uid' => "e$i"];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('calendarobjects')->insert($chunk);
        }

        unset($rows, $chunk);
        $cursor = app(CalendarItemReader::class)->all($this->ada, $calendar, 50);
        gc_collect_cycles();
        memory_reset_peak_usage();
        $start = memory_get_usage();
        $count = 0;
        $bytes = 0;

        foreach ($cursor as $item) {
            $count++;
            $bytes += strlen($item->data);
        }

        $this->assertSame(600, $count);
        $this->assertGreaterThan(2_400_000, $bytes);
        $this->assertLessThan(1_500_000, memory_get_peak_usage() - $start);
    }

    private function store(): CalendarItemStore
    {
        return app(CalendarItemStore::class);
    }
}
