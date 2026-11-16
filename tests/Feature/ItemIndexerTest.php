<?php

namespace Tests\Feature;

use App\Models\ItemIndex;
use App\Models\User;
use App\Services\Portal\Index\ItemIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Sabre\VObject\EofException;
use Tests\Support\PortalHelpers;
use Tests\TestCase;

/**
 * PR 50b, step 2: the fields each kind of item puts in item_index.
 */
class ItemIndexerTest extends TestCase
{
    use PortalHelpers, RefreshDatabase;

    private const CALENDAR = '/dav/calendars/ada/default/';

    private const BOOK = '/dav/addressbooks/ada/default/';

    private User $ada;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ada = $this->user('ada');
        $this->token = $this->token($this->ada);
    }

    public function test_an_event_with_a_time_zone_a_series_and_an_exception(): void
    {
        $this->putDav(self::CALENDAR.'dst.ics', $this->ada, $this->token, $this->dstSeries())->assertCreated();

        $this->assertIndexed(true, $this->calendar(), 'dst.ics', [
            'kind' => ItemIndex::EVENT,
            'uid' => 'dst',
            'title' => 'Stand-up',
            'starts_at' => '2026-03-27 08:00:00',
            'ends_at' => '2026-03-27 09:00:00',
            'all_day' => false,
            'floating' => false,
            'recurring' => true,
        ]);
        $this->assertSame(1, DB::table('item_index')->where('uid', 'dst')->count(), 'Overrides are not extra rows.');
    }

    public function test_all_day_floating_and_override_only_events(): void
    {
        $allDay = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VEVENT\r\nUID:day\r\nDTSTAMP:20260101T000000Z\r\nDTSTART;VALUE=DATE:20260401\r\nSUMMARY:Holiday\r\nSTATUS:confirmed\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $floating = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VEVENT\r\nUID:float\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260401T090000\r\nDURATION:PT90M\r\nSUMMARY:Wake up\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $override = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VEVENT\r\nUID:only\r\nDTSTAMP:20260101T000000Z\r\nRECURRENCE-ID:20260402T100000Z\r\nDTSTART:20260402T110000Z\r\nSUMMARY:Invited once\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        foreach (['day' => $allDay, 'float' => $floating, 'only' => $override] as $uri => $data) {
            $this->putDav(self::CALENDAR.$uri.'.ics', $this->ada, $this->token, $data)->assertCreated();
        }

        $this->assertIndexed(true, $this->calendar(), 'day.ics', ['starts_at' => '2026-04-01 00:00:00', 'ends_at' => '2026-04-02 00:00:00', 'all_day' => true, 'floating' => false, 'status' => 'CONFIRMED']);
        $this->assertIndexed(true, $this->calendar(), 'float.ics', ['starts_at' => '2026-04-01 09:00:00', 'ends_at' => '2026-04-01 10:30:00', 'all_day' => false, 'floating' => true]);
        $this->assertIndexed(true, $this->calendar(), 'only.ics', ['title' => 'Invited once', 'starts_at' => '2026-04-02 11:00:00', 'recurring' => true]);
    }

    public function test_a_task_with_a_parent_and_a_note(): void
    {
        $task = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VTODO\r\nUID:child\r\nDTSTAMP:20260101T000000Z\r\nSUMMARY:Buy milk\r\nDUE;VALUE=DATE:20260410\r\nSTATUS:COMPLETED\r\nCOMPLETED:20260409T120000Z\r\nRELATED-TO;RELTYPE=SIBLING:other\r\nRELATED-TO:parent-uid\r\nRRULE:FREQ=WEEKLY\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
        $note = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VJOURNAL\r\nUID:note\r\nDTSTAMP:20260101T000000Z\r\nDTSTART;VALUE=DATE:20260411\r\nSUMMARY:Diary\r\nDESCRIPTION:Long day\r\nEND:VJOURNAL\r\nEND:VCALENDAR\r\n";
        $this->putDav(self::CALENDAR.'task.ics', $this->ada, $this->token, $task)->assertCreated();
        $this->putDav(self::CALENDAR.'note.ics', $this->ada, $this->token, $note)->assertCreated();

        $this->assertIndexed(true, $this->calendar(), 'task.ics', [
            'kind' => ItemIndex::TASK, 'title' => 'Buy milk', 'due_at' => '2026-04-10 00:00:00', 'all_day' => true,
            'completed_at' => '2026-04-09 12:00:00', 'status' => 'COMPLETED', 'parent_uid' => 'parent-uid', 'recurring' => true, 'starts_at' => null,
        ]);
        $this->assertIndexed(true, $this->calendar(), 'note.ics', ['kind' => ItemIndex::NOTE, 'title' => 'Diary', 'starts_at' => '2026-04-11 00:00:00', 'all_day' => true, 'search_text' => 'diary long day']);
    }

    public function test_contacts_vcard_3_and_4_without_n_and_in_cyrillic(): void
    {
        $three = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:ivan\r\nFN:Иван Петров\r\nN:Петров;Иван;;;\r\nORG:ООО Ромашка;Отдел\r\nEMAIL;TYPE=work:ivan@example.com\r\nEMAIL:second@example.com\r\nTEL;TYPE=cell:+7 (912) 555-12-34\r\nEND:VCARD\r\n";
        $four = "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:urn:uuid:tb\r\nFN:Thunder Bird\r\nN:Bird;Thunder;;;\r\nTEL;VALUE=uri:tel:+1-555-0100\r\nEND:VCARD\r\n";
        $noN = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:acme\r\nFN:ACME Support\r\nN:;;;;\r\nEND:VCARD\r\n";

        foreach (['ivan' => $three, 'tb' => $four, 'acme' => $noN] as $uri => $card) {
            $this->putDav(self::BOOK.$uri.'.vcf', $this->ada, $this->token, $card)->assertCreated();
        }

        $book = $this->addressBookId($this->ada);
        $this->assertIndexed(false, $book, 'ivan.vcf', [
            'kind' => ItemIndex::CONTACT, 'uid' => 'ivan', 'title' => 'Иван Петров', 'sort_name' => 'петров иван',
            'organization' => 'ООО Ромашка', 'email' => 'ivan@example.com', 'phone' => '+7 (912) 555-12-34',
        ]);
        $search = (string) $this->indexRow(false, $book, 'ivan.vcf')['search_text'];
        $this->assertStringContainsString('иван петров', $search);
        $this->assertStringContainsString('second@example.com', $search);
        $this->assertStringContainsString('79125551234', $search);

        $this->assertIndexed(false, $book, 'tb.vcf', ['uid' => 'urn:uuid:tb', 'sort_name' => 'bird thunder', 'phone' => '+1-555-0100']);
        $this->assertIndexed(false, $book, 'acme.vcf', ['title' => 'ACME Support', 'sort_name' => 'acme support', 'organization' => null, 'email' => null]);
    }

    public function test_an_object_that_cannot_be_parsed_keeps_a_version_0_row_and_logs_no_content(): void
    {
        $lines = [];
        Log::listen(function (MessageLogged $event) use (&$lines): void {
            $lines[] = [$event->level, $event->message, $event->context];
        });

        app(ItemIndexer::class)->indexCalendarObject($this->calendar(), 'secret-name.ics', "BEGIN:VCALENDAR\r\nBEGIN:VTODO\r\nSUMMARY:Secret plans", '"abc"');

        $row = $this->indexRow(true, $this->calendar(), 'secret-name.ics');
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row['indexer_version']);
        $this->assertSame(ItemIndex::TASK, $row['kind']);
        $this->assertSame('abc', $row['etag']);
        $this->assertNull($row['title']);
        $this->assertSame([['warning', 'An item could not be indexed', ['calendar' => $this->calendar(), 'error' => EofException::class]]], $lines);
        $this->assertStringNotContainsString('Secret', (string) json_encode($lines));
        $this->assertStringNotContainsString('secret-name', (string) json_encode($lines));
    }

    public function test_values_the_database_cannot_hold_never_leave_an_item_without_a_row(): void
    {
        // Year 0 is valid iCalendar and a valid DAV write, but no PostgreSQL timestamp.
        $yearZero = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VEVENT\r\nUID:zero\r\nDTSTAMP:20260101T000000Z\r\nDTSTART;VALUE=DATE:00000101\r\nSUMMARY:Year zero\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $this->putDav(self::CALENDAR.'zero.ics', $this->ada, $this->token, $yearZero)->assertCreated();
        $this->assertIndexed(true, $this->calendar(), 'zero.ics', ['title' => 'Year zero', 'starts_at' => null]);

        // A NUL byte is dropped from the indexed text.
        app(ItemIndexer::class)->indexCalendarObject($this->calendar(), 'nul.ics', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VEVENT\r\nUID:nul\r\nDTSTAMP:20260101T000000Z\r\nSUMMARY:Bad\0byte\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n", 'e1');
        $this->assertSame('Badbyte', $this->indexRow(true, $this->calendar(), 'nul.ics')['title']);

        // A row the database still refuses falls back to the version-0 row.
        $indexer = app(ItemIndexer::class);
        $row = $indexer->row(true, $this->calendar(), 'refused.ics', $this->event('refused', 'Fine'), 'e2');
        $row['all_day'] = null;
        $version = $indexer->writeSafely($row);
        $stored = $this->indexRow(true, $this->calendar(), 'refused.ics');

        $this->assertNotNull($stored);
        $this->assertSame($version, (int) $stored['indexer_version']);
        $this->assertSame(0, $version);
        $this->assertNull($stored['title']);
    }

    private function calendar(): int
    {
        return $this->calendarId($this->ada);
    }
}
