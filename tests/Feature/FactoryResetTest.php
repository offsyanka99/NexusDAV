<?php

namespace Tests\Feature;

use App\Listeners\PauseWorkerWhileResetting;
use App\Models\CalendarInstance;
use App\Models\DiagnosticEvent;
use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\Dav\SharingService;
use App\Services\FactoryReset;
use App\Services\Push\PushKeys;
use App\Services\ResetFlag;
use App\Services\SettingsService;
use App\Services\UserService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * PR 46 of the Version 1.x.x plan: the factory reset on the server.
 */
class FactoryResetTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    private string $base;

    private string $root;

    private User $root_admin;

    private User $ada;

    private string $adminToken;

    private string $adaToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir().'/nexusdav-reset-test-'.bin2hex(random_bytes(6));
        $this->root = $this->base.'/files';
        config([
            'nexusdav.files.path' => $this->root,
            'nexusdav.features.files' => true,
            'nexusdav.env_set' => [],
        ]);
        $this->beforeApplicationDestroyed(function (): void {
            if (is_dir($this->base)) {
                exec('chmod -R u+rwx '.escapeshellarg($this->base));
                File::deleteDirectory($this->base);
            }
        });

        $users = app(UserService::class);
        $this->root_admin = $users->create(['username' => 'root', 'display_name' => 'Root', 'email' => 'root@example.com', 'is_admin' => true, 'password' => self::PASSWORD]);
        $this->ada = $users->create(['username' => 'ada', 'display_name' => 'Ada', 'email' => 'ada@example.com']);
        $users->create(['username' => 'bob', 'display_name' => 'Bob', 'email' => 'bob@example.com']);
        $this->adminToken = app(AppPasswordService::class)->issue($this->root_admin, 'laptop')['token'];
        $this->adaToken = app(AppPasswordService::class)->issue($this->ada, 'phone')['token'];
    }

    public function test_the_server_returns_to_a_fresh_install_with_the_admin(): void
    {
        $this->fill();
        $logged = $this->captureLog();
        $oldKey = app(PushKeys::class)->vapidPublicKey();

        $result = app(FactoryReset::class)->run($this->root_admin, 'keep-me');

        $this->assertSame(2, $result->users);
        $this->assertSame(4, $result->calendars);
        $this->assertSame(4, $result->addressBooks);
        $this->assertSame(3, $result->items);
        $this->assertSame(11, $result->fileBytes);

        $this->assertSame(['root'], DB::table('users')->pluck('username')->all());
        $this->assertSame(['principals/root'], DB::table('principals')->pluck('uri')->all());
        $this->assertSame([['principals/root', 'default', CalendarInstance::OWNER]], DB::table('calendarinstances')->get(['principaluri', 'uri', 'access'])->map(fn (object $row): array => [$row->principaluri, $row->uri, (int) $row->access])->all());
        $this->assertSame(['default'], DB::table('addressbooks')->where('principaluri', 'principals/root')->pluck('uri')->all());
        $this->assertSame(1, DB::table('calendars')->count());
        $this->assertSame(1, DB::table('addressbooks')->count());

        foreach (['calendarobjects', 'calendarchanges', 'calendarsubscriptions', 'schedulingobjects', 'cards', 'addressbookchanges', 'addressbookinstances', 'locks', 'propertystorage',
            'app_passwords', 'push_subscriptions', 'push_outbox', 'diagnostic_events', 'settings', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table.' is not empty');
        }

        $this->assertSame(['keep-me'], DB::table('sessions')->pluck('id')->all());
        $this->assertSame(1, DB::table('worker_heartbeats')->count());
        $this->assertFalse(file_exists($this->root));
        $this->assertSame([], glob($this->base.'/.files-reset-*'));
        $this->assertFalse(app(ResetFlag::class)->active());

        $admin = User::query()->where('username', 'root')->firstOrFail();
        $this->assertTrue(Hash::check(self::PASSWORD, (string) $admin->password));
        $this->assertTrue($admin->is_admin);
        $this->assertTrue((bool) $admin->collections_provisioned);

        $this->assertSame(128, config('nexusdav.files.max_upload_mb'));
        $this->assertSame(128, app(SettingsService::class)->get('files_max_upload_mb'));
        $this->assertNotSame($oldKey, app(PushKeys::class)->vapidPublicKey());

        $this->dav('PROPFIND', '/dav/', 'root', $this->adminToken)->assertUnauthorized();
        $this->dav('PROPFIND', '/dav/', 'ada', $this->adaToken)->assertUnauthorized();

        $this->assertContains(['warning', 'Factory reset', [
            'admin' => 'root', 'users' => 2, 'calendars' => 4, 'address_books' => 4, 'items' => 3, 'file_bytes' => 11,
        ]], $logged->lines);
    }

    public function test_the_admins_new_app_password_and_files_work_after_the_reset(): void
    {
        $this->fill();
        app(FactoryReset::class)->run($this->root_admin, 'keep-me');

        $token = app(AppPasswordService::class)->issue($this->root_admin->fresh() ?? $this->root_admin, 'laptop')['token'];
        $this->dav('PROPFIND', '/dav/calendars/root/default/', 'root', $token, ['Depth' => '0'])->assertStatus(207);
        $this->dav('PUT', '/dav/files/root/new.txt', 'root', $token, [], 'fresh')->assertCreated();
        $this->assertSame('fresh', file_get_contents($this->root.'/root/new.txt'));
    }

    public function test_a_setting_from_the_environment_still_applies(): void
    {
        config(['nexusdav.env_set' => ['LOG_LEVEL'], 'logging.channels.stderr.level' => 'error']);
        // Saved before LOG_LEVEL was set; the environment wins over it.
        DB::table('settings')->insert(['key' => 'log_level', 'value' => '"warning"', 'created_at' => now(), 'updated_at' => now()]);
        app(SettingsService::class)->save(['session_lifetime' => 30]);
        $this->assertSame('error', config('logging.channels.stderr.level'));
        $this->assertSame(30, config('session.lifetime'));

        app(FactoryReset::class)->run($this->root_admin, 'keep-me');

        $this->assertSame('error', config('logging.channels.stderr.level'));
        $this->assertSame(120, config('session.lifetime'));
    }

    public function test_while_the_flag_is_set_dav_and_the_panel_answer_503_and_the_worker_waits(): void
    {
        $flag = app(ResetFlag::class);
        $flag->set();

        $dav = $this->dav('PROPFIND', '/dav/', 'ada', $this->adaToken);
        $dav->assertStatus(503);
        $this->assertSame('30', $dav->headers->get('Retry-After'));
        $this->dav('PROPFIND', '/dav/', 'ada', 'wrong')->assertStatus(503);
        $this->get('/admin/login')->assertStatus(503)->assertSee('The server is being reset');
        $this->get('/health')->assertOk();

        $looping = new Looping('database', 'default', new WorkerOptions);
        $this->assertFalse(app(PauseWorkerWhileResetting::class)->handle($looping));
        $this->assertFalse(event($looping, halt: true) ?? true);

        $flag->clear();
        $this->dav('PROPFIND', '/dav/', 'ada', $this->adaToken, ['Depth' => '0'])->assertStatus(207);
        $this->assertNull(app(PauseWorkerWhileResetting::class)->handle($looping));

        // A flag from a reset that died long ago does not keep the server closed.
        File::ensureDirectoryExists(dirname($flag->path()));
        file_put_contents($flag->path(), (string) (time() - ResetFlag::STALE_SECONDS - 1));
        $this->assertFalse($flag->active());
        $this->get('/admin/login')->assertOk();
    }

    public function test_a_failing_database_part_changes_nothing(): void
    {
        $this->fill();
        $before = $this->tableCounts();

        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'delete from "sessions"')) {
                throw new RuntimeException('Database gone');
            }
        });

        try {
            app(FactoryReset::class)->run($this->root_admin, 'keep-me');
            $this->fail('The reset should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Database gone', $exception->getMessage());
        }

        $this->assertSame($before, $this->tableCounts());
        $this->assertSame('hello world', file_get_contents($this->root.'/ada/hello.txt'));
        $this->assertSame([], glob($this->base.'/.files-reset-*'));
        $this->assertFalse(app(ResetFlag::class)->active());
    }

    public function test_files_that_cannot_be_deleted_are_logged_and_out_of_service(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can delete a file in a read-only folder.');
        }

        $this->fill();
        chmod($this->root.'/ada', 0500);
        $logged = $this->captureLog('Factory reset could not delete all files');

        app(FactoryReset::class)->run($this->root_admin, 'keep-me');

        $this->assertCount(1, $logged->lines);
        $this->assertFalse(file_exists($this->root));
        $this->assertCount(1, glob($this->base.'/.files-reset-*') ?: []);
        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_only_an_active_admin_can_reset(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        try {
            app(FactoryReset::class)->run($this->ada, 'keep-me');
        } finally {
            $this->assertSame(3, DB::table('users')->count());
            $this->assertFalse(app(ResetFlag::class)->active());
        }
    }

    public function test_every_table_is_either_reset_or_kept(): void
    {
        $tables = array_values(array_filter(
            array_column(Schema::getTables(), 'name'),
            static fn (string $name): bool => ! str_starts_with($name, 'sqlite_'),
        ));
        $listed = [...FactoryReset::RESET_TABLES, ...FactoryReset::KEPT_TABLES];
        sort($tables);
        sort($listed);

        $this->assertSame($tables, $listed, 'A new table must be listed in FactoryReset::RESET_TABLES or KEPT_TABLES.');
    }

    /**
     * Two users with calendars, contacts, a share and files, and a row in
     * every other table the reset empties.
     */
    private function fill(): void
    {
        $this->dav('PUT', '/dav/calendars/ada/default/meet.ics', 'ada', $this->adaToken, ['Content-Type' => 'text/calendar'], $this->event('meet'))->assertCreated();
        $this->dav('PUT', '/dav/calendars/root/default/mine.ics', 'root', $this->adminToken, ['Content-Type' => 'text/calendar'], $this->event('mine'))->assertCreated();
        $this->dav('PUT', '/dav/addressbooks/ada/default/card.vcf', 'ada', $this->adaToken, ['Content-Type' => 'text/vcard'],
            "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:card\r\nFN:Card\r\nEND:VCARD\r\n")->assertCreated();
        $this->dav('MKCALENDAR', '/dav/calendars/ada/work/', 'ada', $this->adaToken)->assertCreated();
        $this->dav('MKCOL', '/dav/addressbooks/root/team/', 'root', $this->adminToken, ['Content-Type' => 'application/xml'],
            '<?xml version="1.0"?><d:mkcol xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:carddav"><d:set><d:prop><d:resourcetype><d:collection/><c:addressbook/></d:resourcetype></d:prop></d:set></d:mkcol>')->assertCreated();
        $this->dav('PROPPATCH', '/dav/calendars/ada/default/meet.ics', 'ada', $this->adaToken, ['Content-Type' => 'application/xml'],
            '<?xml version="1.0"?><d:propertyupdate xmlns:d="DAV:" xmlns:x="urn:example"><d:set><d:prop><x:note>n</x:note></d:prop></d:set></d:propertyupdate>')->assertStatus(207);
        $this->dav('PUT', '/dav/files/ada/hello.txt', 'ada', $this->adaToken, [], 'hello world')->assertCreated();

        $calendar = CalendarInstance::query()->where('principaluri', 'principals/ada')->where('uri', 'default')->firstOrFail();
        app(SharingService::class)->share($calendar, User::query()->where('username', 'bob')->firstOrFail(), SharingService::READ);

        app(SettingsService::class)->save(['files_max_upload_mb' => 512]);
        app(PushKeys::class)->vapidPublicKey();

        $now = now();
        DB::table('diagnostic_events')->insert(['occurred_at' => $now, 'source' => DiagnosticEvent::SOURCE_DAV, 'kind' => DiagnosticEvent::KIND_HTTP_ERROR, 'ip' => '127.0.0.1',
            'username' => 'ada', 'method' => 'GET', 'path' => '/dav/', 'status' => 404, 'reason' => 'Not Found', 'repeat_count' => 1, 'bucket' => 1]);
        DB::table('locks')->insert(['owner' => 'ada', 'timeout' => 600, 'created' => time(), 'token' => 'opaquelocktoken:x', 'scope' => 1, 'depth' => 0, 'uri' => 'files/ada/hello.txt']);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);
        DB::table('failed_jobs')->insert(['uuid' => 'u-1', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => $now]);
        DB::table('job_batches')->insert(['id' => 'b-1', 'name' => 'b', 'total_jobs' => 0, 'pending_jobs' => 0, 'failed_jobs' => 0, 'failed_job_ids' => '[]', 'created_at' => time()]);
        DB::table('cache')->insert(['key' => 'k', 'value' => 'v', 'expiration' => time() + 60]);
        DB::table('cache_locks')->insert(['key' => 'l', 'owner' => 'o', 'expiration' => time() + 60]);
        DB::table('push_outbox')->insert(['topic' => 't', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('worker_heartbeats')->insert(['worker' => 'w', 'started_at' => $now, 'beat_at' => $now]);

        foreach (['keep-me' => $this->root_admin, 'admin-phone' => $this->root_admin, 'ada-1' => $this->ada] as $id => $user) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->getKey(), 'payload' => '', 'last_activity' => time()]);
        }
    }

    /**
     * @return array<string, int>
     */
    private function tableCounts(): array
    {
        $counts = [];

        foreach ([...FactoryReset::RESET_TABLES, ...FactoryReset::KEPT_TABLES] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function dav(string $method, string $uri, string $username, string $password, array $headers = [], ?string $body = null): TestResponse
    {
        $server = ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode($username.':'.$password)];

        foreach ($headers as $name => $value) {
            $server[$name === 'Content-Type' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call($method, $uri, [], [], [], $server, $body);
    }

    private function event(string $uid): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\nBEGIN:VEVENT\r\nUID:{$uid}\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20270101T100000Z\r\nDTEND:20270101T110000Z\r\nSUMMARY:Event\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    private function captureLog(string $prefix = 'Factory reset'): object
    {
        $logged = new \stdClass;
        $logged->lines = [];

        Log::listen(function (MessageLogged $event) use ($logged, $prefix): void {
            if ($event->message === $prefix) {
                $logged->lines[] = [$event->level, $event->message, $event->context];
            }
        });

        return $logged;
    }
}
