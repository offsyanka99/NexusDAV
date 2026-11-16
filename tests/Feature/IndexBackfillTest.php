<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Portal\Index\IndexBackfill;
use App\Services\Portal\Index\ItemIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\PortalHelpers;
use Tests\TestCase;

/**
 * PR 50b, step 4: nexusdav:index-items brings the index in step with the
 * DAV rows and never overwrites a newer row a write's hook put there.
 */
class IndexBackfillTest extends TestCase
{
    use PortalHelpers, RefreshDatabase;

    private User $ada;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ada = $this->user('ada');
        $this->token = $this->token($this->ada);
    }

    public function test_the_first_run_indexes_everything_and_the_second_changes_nothing(): void
    {
        $calendar = $this->calendarId($this->ada);
        $this->bulkEvents($calendar, IndexBackfill::CHUNK + 30);
        $book = $this->addressBookId($this->ada);
        $card = $this->card('c', 'Contact');
        DB::table('cards')->insert(['addressbookid' => $book, 'uri' => 'c.vcf', 'carddata' => $card, 'etag' => md5($card), 'size' => strlen($card), 'lastmodified' => time()]);

        $first = app(IndexBackfill::class)->run();

        $this->assertSame(['indexed' => IndexBackfill::CHUNK + 31, 'failed' => 0, 'changed' => 0, 'removed' => 0], $first);
        $this->assertIndexed(true, $calendar, 'bulk7.ics', ['title' => 'Bulk 7', 'starts_at' => '2026-06-01 10:00:00']);
        $this->assertIndexed(false, $book, 'c.vcf', ['title' => 'Contact']);

        $this->assertSame(['indexed' => 0, 'failed' => 0, 'changed' => 0, 'removed' => 0], app(IndexBackfill::class)->run());
    }

    public function test_an_older_version_or_another_etag_is_rebuilt_and_rows_of_gone_objects_are_removed(): void
    {
        $calendar = $this->calendarId($this->ada);
        $this->putDav('/dav/calendars/ada/default/a.ics', $this->ada, $this->token, $this->event('a', 'Current'))->assertCreated();
        $this->putDav('/dav/calendars/ada/default/b.ics', $this->ada, $this->token, $this->event('b'))->assertCreated();
        $this->putDav('/dav/calendars/ada/default/gone.ics', $this->ada, $this->token, $this->event('gone'))->assertCreated();

        DB::table('item_index')->where('uri', 'a.ics')->update(['indexer_version' => ItemIndexer::VERSION - 1, 'title' => 'Stale']);
        DB::table('item_index')->where('uri', 'b.ics')->update(['etag' => 'old', 'title' => 'Stale']);
        DB::table('calendarobjects')->where('uri', 'gone.ics')->delete();

        $counts = app(IndexBackfill::class)->run();

        $this->assertSame(['indexed' => 2, 'failed' => 0, 'changed' => 0, 'removed' => 1], $counts);
        $this->assertIndexed(true, $calendar, 'a.ics', ['title' => 'Current']);
        $this->assertIndexed(true, $calendar, 'b.ics', ['title' => 'Event']);
        $this->assertNotIndexed(true, $calendar, 'gone.ics');
    }

    public function test_a_write_that_lands_after_the_backfill_parsed_an_object_keeps_its_newer_row(): void
    {
        $calendar = $this->calendarId($this->ada);
        $this->bulkEvents($calendar, 3);

        $backfill = app(IndexBackfill::class);
        $backfill->beforeWrite(function (): void {
            // A client changes bulk1 between the backfill's read and its write.
            $this->putDav('/dav/calendars/ada/default/bulk1.ics', $this->ada, $this->token, $this->event('bulk1', 'Changed by a client'))->assertNoContent();
        });

        $counts = $backfill->run();

        $this->assertSame(['indexed' => 2, 'failed' => 0, 'changed' => 1, 'removed' => 0], $counts);
        $this->assertIndexed(true, $calendar, 'bulk1.ics', ['title' => 'Changed by a client']);
        $this->assertIndexed(true, $calendar, 'bulk0.ics', ['title' => 'Bulk 0']);
    }

    public function test_an_unreadable_object_keeps_a_version_0_row_that_is_retried(): void
    {
        $calendar = $this->calendarId($this->ada);
        $bad = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nSUMMARY:cut off";
        DB::table('calendarobjects')->insert(['calendarid' => $calendar, 'uri' => 'bad.ics', 'calendardata' => $bad, 'etag' => md5($bad), 'size' => strlen($bad), 'lastmodified' => time(), 'componenttype' => 'VEVENT', 'uid' => 'bad']);

        $this->assertSame(['indexed' => 0, 'failed' => 1, 'changed' => 0, 'removed' => 0], app(IndexBackfill::class)->run());
        $this->assertSame(0, (int) $this->indexRow(true, $calendar, 'bad.ics')['indexer_version']);
        $this->assertSame(1, app(IndexBackfill::class)->run()['failed']);
    }

    public function test_an_object_the_database_cannot_index_fully_does_not_stop_the_run(): void
    {
        $calendar = $this->calendarId($this->ada);
        $bad = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VEVENT\r\nUID:zero\r\nDTSTAMP:20260101T000000Z\r\nDTSTART;VALUE=DATE:00000101\r\nDUE:99991231T000000Z\r\nSUMMARY:Year zero\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        DB::table('calendarobjects')->insert(['calendarid' => $calendar, 'uri' => 'zero.ics', 'calendardata' => $bad, 'etag' => md5($bad), 'size' => strlen($bad), 'lastmodified' => time(), 'componenttype' => 'VEVENT', 'uid' => 'zero']);
        $this->bulkEvents($calendar, 2);
        $card = $this->card('c', 'Later');
        DB::table('cards')->insert(['addressbookid' => $this->addressBookId($this->ada), 'uri' => 'c.vcf', 'carddata' => $card, 'etag' => md5($card), 'size' => strlen($card), 'lastmodified' => time()]);

        $this->assertSame(['indexed' => 4, 'failed' => 0, 'changed' => 0, 'removed' => 0], app(IndexBackfill::class)->run());
        $this->assertIndexed(true, $calendar, 'zero.ics', ['title' => 'Year zero', 'starts_at' => null]);
        $this->assertIndexed(false, $this->addressBookId($this->ada), 'c.vcf');
    }

    public function test_the_command_and_prepare_run_it(): void
    {
        $this->bulkEvents($this->calendarId($this->ada), 2);

        $this->assertSame(0, Artisan::call('nexusdav:index-items'));
        $this->assertStringContainsString('Item index: 2 indexed, 0 not readable (kept by name), 0 changed during the run, 0 removed', Artisan::output());

        $this->bulkEvents($this->calendarId($this->ada), 1, 'later');
        $names = ['APP_URL' => 'http://localhost:8080', 'ADMIN_EMAIL' => 'root@example.com', 'ADMIN_PASSWORD' => 'correct-horse-battery'];
        $saved = [];

        foreach ($names as $name => $value) {
            $saved[$name] = getenv($name);
            putenv($name.'='.$value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }

        try {
            $this->artisan('nexusdav:prepare')
                ->expectsOutputToContain('Item index: 1 indexed')
                ->assertSuccessful();
        } finally {
            foreach ($saved as $name => $value) {
                if ($value === false) {
                    putenv($name);
                    unset($_ENV[$name], $_SERVER[$name]);
                } else {
                    putenv($name.'='.$value);
                    $_ENV[$name] = $_SERVER[$name] = $value;
                }
            }
        }
    }
}
