<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\Dav\SyncHistoryPruner;
use App\Services\SettingsService;
use App\Services\UserService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * PR 15: sync history is kept for a limited number of days.
 */
class SyncHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = 'http://sabre.io/ns/sync/';

    private User $ada;

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'nexusdav.sync_history.retention_days' => 90,
            'nexusdav.sync_history.prune_batch' => 1000,
            'nexusdav.env_set' => [],
        ]);

        $this->ada = app(UserService::class)->create(['username' => 'ada', 'display_name' => 'Ada', 'email' => 'ada@example.com']);
        $this->password = app(AppPasswordService::class)->issue($this->ada, 'phone')['token'];
        Cache::forget('nexusdav.sync_history.prune');
    }

    public function test_a_row_at_the_retention_boundary_is_kept_and_an_older_row_is_deleted(): void
    {
        $this->freezeTime();
        $this->makeCalendar();
        $this->putEvents(['older', 'boundary']);

        $cutoff = now()->subDays(90);
        $this->stamp('calendarchanges', 'older.ics', $cutoff->copy()->subSecond());
        $this->stamp('calendarchanges', 'boundary.ics', $cutoff);

        $this->prune();

        $this->assertSame(['boundary.ics'], $this->uris('calendarchanges'));
        $this->assertSame($this->changeNumber('calendarchanges', 'boundary.ics'), $this->floor('calendars'));
    }

    public function test_history_shorter_than_the_setting_is_not_pruned(): void
    {
        $this->makeCalendar();
        $this->putEvents(['one', 'two']);
        $before = $this->uris('calendarchanges');

        $this->prune();

        $this->assertSame($before, $this->uris('calendarchanges'));
        $this->assertSame(1, $this->floor('calendars'));
    }

    public function test_pruning_deletes_one_batch_and_moves_the_oldest_change_number(): void
    {
        config(['nexusdav.sync_history.prune_batch' => 2]);
        $this->makeCalendar();
        $this->putEvents(['a', 'b', 'c', 'd', 'e']);
        $this->backdate('calendarchanges', ['a.ics', 'b.ics', 'c.ics', 'd.ics', 'e.ics']);

        $this->prune();

        $this->assertSame(['c.ics', 'd.ics', 'e.ics'], $this->uris('calendarchanges'));
        $this->assertSame($this->changeNumber('calendarchanges', 'c.ics'), $this->floor('calendars'));

        $this->prune();

        $this->assertSame(['e.ics'], $this->uris('calendarchanges'));
        $this->assertSame($this->changeNumber('calendarchanges', 'e.ics'), $this->floor('calendars'));
    }

    public function test_a_calendar_token_older_than_the_history_is_refused_and_a_newer_token_returns_only_later_changes(): void
    {
        $this->makeCalendar();
        $this->putEvents(['a']);
        $afterA = $this->syncToken($this->sync('/dav/calendars/ada/history/'));
        $this->putEvents(['b']);
        $afterB = $this->syncToken($this->sync('/dav/calendars/ada/history/'));
        $this->putEvents(['c', 'd']);
        $this->backdate('calendarchanges', ['a.ics', 'b.ics']);

        $this->prune();

        $this->assertInvalidToken($this->sync('/dav/calendars/ada/history/', $afterA));

        $body = $this->body($this->sync('/dav/calendars/ada/history/', $afterB)->assertStatus(207));
        $this->assertStringContainsString('c.ics', $body);
        $this->assertStringContainsString('d.ics', $body);
        $this->assertStringNotContainsString('a.ics', $body);
        $this->assertStringNotContainsString('b.ics', $body);
    }

    public function test_an_address_book_token_older_than_the_history_is_refused_and_a_newer_token_returns_only_later_changes(): void
    {
        $this->makeAddressBook();
        $this->putCards(['a']);
        $afterA = $this->syncToken($this->sync('/dav/addressbooks/ada/history/'));
        $this->putCards(['b']);
        $afterB = $this->syncToken($this->sync('/dav/addressbooks/ada/history/'));
        $this->putCards(['c']);
        $this->backdate('addressbookchanges', ['a.vcf', 'b.vcf']);

        $this->prune();

        $this->assertInvalidToken($this->sync('/dav/addressbooks/ada/history/', $afterA));

        $body = $this->body($this->sync('/dav/addressbooks/ada/history/', $afterB)->assertStatus(207));
        $this->assertStringContainsString('c.vcf', $body);
        $this->assertStringNotContainsString('a.vcf', $body);
        $this->assertStringNotContainsString('b.vcf', $body);
    }

    public function test_a_write_prunes_expired_history(): void
    {
        $this->makeCalendar();
        $this->putEvents(['old']);
        $kept = $this->syncToken($this->sync('/dav/calendars/ada/history/'));
        $this->backdate('calendarchanges', ['old.ics']);
        Cache::forget('nexusdav.sync_history.prune');

        $this->putEvents(['new']);

        $this->assertSame(['new.ics'], $this->uris('calendarchanges'));
        $this->assertInvalidToken($this->sync('/dav/calendars/ada/history/', self::PREFIX.$this->calendarId().'-1'));

        $body = $this->body($this->sync('/dav/calendars/ada/history/', $kept)->assertStatus(207));
        $this->assertStringContainsString('new.ics', $body);
        $this->assertStringNotContainsString('old.ics', $body);
    }

    public function test_the_setting_is_saved_from_the_panel_and_locked_by_the_environment(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(app(UserService::class)->create([
            'username' => 'root',
            'display_name' => 'Root',
            'email' => 'root@example.com',
            'is_admin' => true,
            'password' => 'correct-horse-battery',
        ]));

        Livewire::test(Settings::class)
            ->assertSet('data.sync_history_days', 90)
            ->fillForm(['sync_history_days' => 14])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(14, app(SettingsService::class)->get('sync_history_days'));

        config([
            'nexusdav.env_set' => ['NEXUSDAV_SYNC_HISTORY_DAYS'],
            'nexusdav.sync_history.retention_days' => 30,
        ]);
        app(SettingsService::class)->forget();

        Livewire::test(Settings::class)
            ->assertFormFieldDisabled('sync_history_days')
            ->assertSee('Set by NEXUSDAV_SYNC_HISTORY_DAYS');

        $this->assertSame(30, app(SettingsService::class)->get('sync_history_days'));
    }

    /**
     * created_at is the database clock, and on PostgreSQL it is the time the
     * writing transaction started. A transaction that started first can get
     * the later change number, so a newer change can carry the older time.
     * Deleting it must still refuse every token that would have needed it.
     */
    public function test_a_change_deleted_out_of_order_refuses_every_token_that_needed_it(): void
    {
        $this->makeCalendar();
        $this->putEvents(['a']);
        $afterA = $this->syncToken($this->sync('/dav/calendars/ada/history/'));
        $this->putEvents(['b']);
        $this->stamp('calendarchanges', 'b.ics', now()->subDays(200));

        $this->prune();

        $this->assertSame(['a.ics'], $this->uris('calendarchanges'));
        $this->assertInvalidToken($this->sync('/dav/calendars/ada/history/', $afterA));
    }

    public function test_a_change_is_stamped_with_the_current_utc_time(): void
    {
        $this->makeCalendar();
        $this->putEvents(['now']);

        $stamped = DB::table('calendarchanges')->where('uri', 'now.ics')->value('created_at');

        $this->assertNotNull($stamped);
        $this->assertLessThan(120, abs(strtotime((string) $stamped.' UTC') - now()->getTimestamp()), 'created_at must be UTC: '.$stamped);
    }

    /**
     * SQLite cannot add a CURRENT_TIMESTAMP column to a table with rows, so an
     * upgrade with existing history used to stop at the migration.
     */
    public function test_the_migration_upgrades_a_database_that_already_has_history(): void
    {
        $this->makeCalendar();
        $this->putEvents(['before']);
        $migration = require database_path('migrations/2026_11_04_000000_add_sync_history_retention.php');
        $migration->down();
        $this->assertSame(1, DB::table('calendarchanges')->count());

        $migration->up();

        $this->assertSame(0, DB::table('calendarchanges')->whereNull('created_at')->count());
        $this->putEvents(['after']);
        $this->assertNotNull(DB::table('calendarchanges')->where('uri', 'after.ics')->value('created_at'));
        $this->assertSame(1, (int) DB::table('calendars')->where('id', $this->calendarId())->value('synctoken_min'));
    }

    public function test_a_failing_prune_does_not_fail_the_client_write(): void
    {
        $this->makeCalendar();
        $this->app->instance(SyncHistoryPruner::class, new class extends SyncHistoryPruner
        {
            protected function pruneTable(string $changes, string $collections, string $foreignKey): void
            {
                throw new \RuntimeException('simulated prune failure');
            }
        });

        $this->putEvents(['kept']);

        $this->assertSame(['kept.ics'], $this->uris('calendarchanges'));
    }

    private function prune(): void
    {
        Cache::forget('nexusdav.sync_history.prune');
        app(SyncHistoryPruner::class)->afterWrite();
    }

    private function makeCalendar(): void
    {
        $this->dav('MKCALENDAR', '/dav/calendars/ada/history/')->assertCreated();
        Cache::forget('nexusdav.sync_history.prune');
    }

    private function makeAddressBook(): void
    {
        $this->dav('MKCOL', '/dav/addressbooks/ada/history/', ['Content-Type' => 'application/xml'],
            '<?xml version="1.0"?><d:mkcol xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav"><d:set><d:prop><d:resourcetype><d:collection/><card:addressbook/></d:resourcetype><d:displayname>History</d:displayname></d:prop></d:set></d:mkcol>')
            ->assertCreated();
        Cache::forget('nexusdav.sync_history.prune');
    }

    /**
     * @param  list<string>  $names
     */
    private function putEvents(array $names): void
    {
        foreach ($names as $name) {
            $this->dav('PUT', '/dav/calendars/ada/history/'.$name.'.ics', ['Content-Type' => 'text/calendar'],
                "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//NexusDAV//test//EN\r\nBEGIN:VEVENT\r\nUID:$name\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260101T100000Z\r\nSUMMARY:$name\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n")
                ->assertCreated();
        }
    }

    /**
     * @param  list<string>  $names
     */
    private function putCards(array $names): void
    {
        foreach ($names as $name) {
            $this->dav('PUT', '/dav/addressbooks/ada/history/'.$name.'.vcf', ['Content-Type' => 'text/vcard'],
                "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:$name\r\nFN:$name\r\nN:$name;;;;\r\nEND:VCARD\r\n")
                ->assertCreated();
        }
    }

    /**
     * @param  list<string>  $uris
     */
    private function backdate(string $table, array $uris): void
    {
        $age = now()->subDays(200);

        foreach ($uris as $index => $uri) {
            $this->stamp($table, $uri, $age->copy()->addSeconds($index));
        }
    }

    private function stamp(string $table, string $uri, mixed $at): void
    {
        $foreignKey = $table === 'calendarchanges' ? 'calendarid' : 'addressbookid';

        DB::table($table)
            ->where($foreignKey, $this->collectionId($table))
            ->where('uri', $uri)
            ->update(['created_at' => $at]);
    }

    /**
     * @return list<string>
     */
    private function uris(string $table): array
    {
        $foreignKey = $table === 'calendarchanges' ? 'calendarid' : 'addressbookid';

        return DB::table($table)
            ->where($foreignKey, $this->collectionId($table))
            ->orderBy('synctoken')
            ->pluck('uri')
            ->all();
    }

    private function changeNumber(string $table, string $uri): int
    {
        $foreignKey = $table === 'calendarchanges' ? 'calendarid' : 'addressbookid';

        return (int) DB::table($table)
            ->where($foreignKey, $this->collectionId($table))
            ->where('uri', $uri)
            ->value('synctoken');
    }

    private function floor(string $table): int
    {
        return (int) DB::table($table)->where('id', $this->collectionId($table === 'calendars' ? 'calendarchanges' : 'addressbookchanges'))->value('synctoken_min');
    }

    private function collectionId(string $changes): int
    {
        return $changes === 'calendarchanges' ? $this->calendarId() : $this->addressBookId();
    }

    private function calendarId(): int
    {
        return (int) DB::table('calendarinstances')->where('uri', 'history')->value('calendarid');
    }

    private function addressBookId(): int
    {
        return (int) DB::table('addressbooks')->where('uri', 'history')->value('id');
    }

    private function sync(string $url, ?string $token = null): TestResponse
    {
        $token = $token === null ? '' : htmlspecialchars($token, ENT_XML1);

        return $this->dav('REPORT', $url, ['Depth' => '0', 'Content-Type' => 'application/xml'],
            '<?xml version="1.0"?><d:sync-collection xmlns:d="DAV:"><d:sync-token>'.$token.'</d:sync-token><d:sync-level>1</d:sync-level><d:prop><d:getetag/></d:prop></d:sync-collection>');
    }

    private function syncToken(TestResponse $response): string
    {
        $body = $this->body($response);
        $this->assertSame(1, preg_match('#<d:sync-token>([^<]+)</d:sync-token>#', $body, $matches), $body);

        return html_entity_decode($matches[1], ENT_XML1);
    }

    private function assertInvalidToken(TestResponse $response): void
    {
        $body = $this->body($response);
        $this->assertSame(403, $response->getStatusCode(), $body);
        $this->assertStringContainsString('valid-sync-token', $body);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function dav(string $method, string $url, array $headers = [], ?string $body = null): TestResponse
    {
        $server = ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode('ada:'.$this->password)];

        foreach ($headers as $name => $value) {
            $server[$name === 'Content-Type' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call($method, $url, [], [], [], $server, $body);
    }

    private function body(TestResponse $response): string
    {
        return $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : (string) $response->getContent();
    }
}
