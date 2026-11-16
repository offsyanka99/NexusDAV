<?php

namespace Tests\Feature;

use App\Models\AddressBook;
use App\Models\CalendarInstance;
use App\Models\User;
use App\Services\Dav\CalendarBackend;
use App\Services\Dav\CollectionService;
use App\Services\Dav\FileStorage;
use App\Services\Dav\SharingService;
use App\Services\FactoryReset;
use App\Services\Portal\CalendarItemStore;
use App\Services\Portal\ContactStore;
use App\Services\UserService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Support\PortalHelpers;
use Tests\TestCase;

/**
 * PR 50b, step 3: every write path keeps item_index in step with the DAV
 * rows, and a failing index never fails the write.
 */
class ItemIndexHooksTest extends TestCase
{
    use PortalHelpers, RefreshDatabase;

    private const CALENDAR = '/dav/calendars/ada/default/';

    private User $ada;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ada = $this->user('ada');
        $this->token = $this->token($this->ada);
    }

    public function test_dav_put_update_delete_and_move_keep_the_index(): void
    {
        $calendar = $this->calendarId($this->ada);
        app(CollectionService::class)->createCalendar($this->ada, 'work', 'Work', null, '#123456', true, true);
        $work = $this->calendarId($this->ada, 'work');

        $this->putDav(self::CALENDAR.'a.ics', $this->ada, $this->token, $this->event('a', 'First'))->assertCreated();
        $this->assertIndexed(true, $calendar, 'a.ics', ['title' => 'First']);

        $this->putDav(self::CALENDAR.'a.ics', $this->ada, $this->token, $this->event('a', 'Second'))->assertNoContent();
        $this->assertIndexed(true, $calendar, 'a.ics', ['title' => 'Second']);

        $this->dav('MOVE', self::CALENDAR.'a.ics', 'ada', $this->token, ['Destination' => 'https://dav.example.org/dav/calendars/ada/work/b.ics'])->assertCreated();
        $this->assertNotIndexed(true, $calendar, 'a.ics');
        $this->assertIndexed(true, $work, 'b.ics', ['title' => 'Second']);

        $this->dav('DELETE', '/dav/calendars/ada/work/b.ics', 'ada', $this->token)->assertNoContent();
        $this->assertNotIndexed(true, $work, 'b.ics');
        $this->assertSame(0, DB::table('item_index')->count());

        $this->putDav('/dav/addressbooks/ada/default/c.vcf', $this->ada, $this->token, $this->card('c', 'Card'))->assertCreated();
        $this->assertIndexed(false, $this->addressBookId($this->ada), 'c.vcf', ['title' => 'Card']);
        $this->dav('DELETE', '/dav/addressbooks/ada/default/c.vcf', 'ada', $this->token)->assertNoContent();
        $this->assertNotIndexed(false, $this->addressBookId($this->ada), 'c.vcf');
    }

    public function test_portal_stores_batches_sharees_and_backend_calls_keep_the_index(): void
    {
        $calendar = $this->calendarId($this->ada);
        $book = $this->addressBookId($this->ada);
        $bob = $this->user('bob');
        $this->shareCalendar($this->ada, $bob, SharingService::READ_WRITE);

        $created = app(CalendarItemStore::class)->create($this->ada, $calendar, 'store.ics', $this->event('store', 'Store'));
        $this->assertIndexed(true, $calendar, 'store.ics', ['title' => 'Store']);
        app(CalendarItemStore::class)->update($this->ada, $calendar, 'store.ics', $this->event('store', 'Updated'), $created->etag);
        $this->assertIndexed(true, $calendar, 'store.ics', ['title' => 'Updated']);

        app(CalendarItemStore::class)->batch($this->ada, $calendar, [$this->event('b1'), $this->event('b2')]);
        $this->assertIndexed(true, $calendar, 'b1.ics');
        $this->assertIndexed(true, $calendar, 'b2.ics');

        // A sharee's write is a row of the same calendar: one set of rows.
        app(CalendarItemStore::class)->create($bob, $calendar, 'bob.ics', $this->event('bob', 'From Bob'));
        $this->assertIndexed(true, $calendar, 'bob.ics', ['title' => 'From Bob']);
        $this->assertSame(4, DB::table('item_index')->where('calendar_id', $calendar)->count());

        // A server-side write through the backend (PR 51's holiday calendars).
        app(CalendarBackend::class)->createCalendarObject([$calendar, 0], 'holiday.ics', $this->event('holiday', 'New Year'));
        $this->assertIndexed(true, $calendar, 'holiday.ics', ['title' => 'New Year']);

        $card = app(ContactStore::class)->create($this->ada, $book, 'p.vcf', $this->card('p', 'Person'));
        app(ContactStore::class)->delete($this->ada, $book, 'p.vcf', $card->etag);
        $this->assertNotIndexed(false, $book, 'p.vcf');
    }

    public function test_rows_go_with_their_collection_their_user_and_a_factory_reset(): void
    {
        app(CollectionService::class)->createCalendar($this->ada, 'work', 'Work', null, '#123456', true, true);
        $work = $this->calendarId($this->ada, 'work');
        $this->putDav('/dav/calendars/ada/work/w.ics', $this->ada, $this->token, $this->event('w'))->assertCreated();
        $this->putDav(self::CALENDAR.'d.ics', $this->ada, $this->token, $this->event('d'))->assertCreated();
        $this->putDav('/dav/addressbooks/ada/default/c.vcf', $this->ada, $this->token, $this->card('c'))->assertCreated();

        app(CollectionService::class)->deleteCalendar($this->ada, CalendarInstance::query()->where('principaluri', 'principals/ada')->where('uri', 'work')->firstOrFail());
        $this->assertSame(0, DB::table('item_index')->where('calendar_id', $work)->count());
        $this->assertSame(2, DB::table('item_index')->count());

        $book = AddressBook::query()->where('principaluri', 'principals/ada')->where('uri', 'default')->firstOrFail();
        app(CollectionService::class)->deleteAddressBook($this->ada, $book);
        $this->assertSame(1, DB::table('item_index')->count());

        $bob = $this->user('bob');
        $bobToken = $this->token($bob);
        $this->putDav('/dav/calendars/bob/default/x.ics', $bob, $bobToken, $this->event('x'))->assertCreated();
        app(UserService::class)->delete($this->ada);
        $this->assertSame(1, DB::table('item_index')->count());

        config(['nexusdav.files.path' => sys_get_temp_dir().'/nexusdav-index-reset-'.bin2hex(random_bytes(4))]);
        $admin = app(UserService::class)->create(['username' => 'root', 'display_name' => 'Root', 'email' => 'root@example.com', 'password' => 'root-password-123', 'is_admin' => true]);
        app(FactoryReset::class)->run($admin, 'keep');
        $this->assertSame(0, DB::table('item_index')->count());
        (new FileStorage)->deleteTree((string) config('nexusdav.files.path'));
    }

    public function test_the_one_collection_check_holds_on_both_databases(): void
    {
        $calendar = $this->calendarId($this->ada);
        $book = $this->addressBookId($this->ada);
        $row = ['uri' => 'x', 'kind' => 'event', 'etag' => 'e', 'indexer_version' => 1];

        foreach ([['calendar_id' => null, 'addressbook_id' => null], ['calendar_id' => $calendar, 'addressbook_id' => $book]] as $ids) {
            try {
                DB::transaction(fn () => DB::table('item_index')->insert([...$row, ...$ids]));
                $this->fail('A row with '.json_encode($ids).' was stored.');
            } catch (QueryException) {
                $this->assertSame(0, DB::table('item_index')->count());
            }
        }

        DB::table('item_index')->insert([...$row, 'calendar_id' => $calendar, 'addressbook_id' => null]);
        $this->assertSame(1, DB::table('item_index')->count());
    }

    public function test_a_failing_index_write_does_not_fail_the_dav_write(): void
    {
        $lines = [];
        Log::listen(function (MessageLogged $event) use (&$lines): void {
            $lines[] = [$event->level, $event->message];
        });
        Schema::rename('item_index', 'item_index_away');

        try {
            $this->putDav(self::CALENDAR.'a.ics', $this->ada, $this->token, $this->event('a'))->assertCreated();
            $this->putDav(self::CALENDAR.'a.ics', $this->ada, $this->token, $this->event('a', 'Again'))->assertNoContent();
            $this->dav('DELETE', self::CALENDAR.'a.ics', 'ada', $this->token)->assertNoContent();
        } finally {
            Schema::rename('item_index_away', 'item_index');
        }

        $this->assertContains(['error', 'The item index could not be updated'], $lines);
        $this->assertSame(0, DB::table('calendarobjects')->count());
    }
}
