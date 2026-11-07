<?php

namespace Tests\Feature;

use App\Models\AddressBook;
use App\Models\CalendarInstance;
use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\Dav\SharingService;
use App\Services\UserService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * PR 16: writes to one collection from separate processes at the same time,
 * against a real database file on SQLite (not :memory:) and a separate
 * database on PostgreSQL. Each worker is a full app instance; the database is
 * shared, so this test does not use RefreshDatabase.
 */
class ConcurrentWritesTest extends TestCase
{
    private const HOLD_MS = 400;

    private string $previousDefault;

    private string $driver;

    private ?string $file = null;

    private ?string $database = null;

    private User $ada;

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousDefault = (string) config('database.default');
        $base = config('database.connections.'.$this->previousDefault);
        $this->driver = (string) $base['driver'];

        if ($this->driver === 'sqlite') {
            $this->file = tempnam(sys_get_temp_dir(), 'nexusdav-concurrency-').'.sqlite';
            touch($this->file);
            $base['database'] = $this->file;
        } else {
            $this->database = $base['database'].'_concurrency';
            DB::statement('DROP DATABASE IF EXISTS "'.$this->database.'" WITH (FORCE)');
            DB::statement('CREATE DATABASE "'.$this->database.'"');
            $base['database'] = $this->database;
        }

        config(['database.connections.concurrency' => $base, 'database.default' => 'concurrency']);
        Artisan::call('migrate', ['--database' => 'concurrency', '--force' => true]);

        $this->ada = app(UserService::class)->create(['username' => 'ada', 'display_name' => 'Ada', 'email' => 'ada@example.com']);
        $this->password = app(AppPasswordService::class)->issue($this->ada, 'phone')['token'];
    }

    protected function tearDown(): void
    {
        config(['database.default' => $this->previousDefault]);
        DB::purge('concurrency');

        if ($this->file !== null) {
            @unlink($this->file);
            @unlink(substr($this->file, 0, -strlen('.sqlite')));
            @unlink($this->file.'-wal');
            @unlink($this->file.'-shm');
        }

        if ($this->database !== null) {
            DB::connection($this->previousDefault)->statement('DROP DATABASE IF EXISTS "'.$this->database.'" WITH (FORCE)');
        }

        parent::tearDown();
    }

    public function test_two_writes_with_the_same_if_match_one_wins_and_the_other_gets_412(): void
    {
        $etag = $this->store('shared.ics', $this->event('shared', 'Original'))->assertCreated()->headers->get('ETag');
        $this->assertNotNull($etag);

        $results = $this->together([
            $this->job('PUT', 'shared.ics', $this->event('shared', 'Title A'), ['If-Match' => $etag]),
            $this->job('PUT', 'shared.ics', $this->event('shared', 'Title B'), ['If-Match' => $etag]),
        ]);

        $this->assertSame([204, 412], $this->statuses($results), json_encode($results));

        $winner = $results[0]['status'] === 204 ? 'Title A' : 'Title B';
        $stored = $this->fetch('shared.ics')->assertOk();
        $this->assertStringContainsString('SUMMARY:'.$winner, $this->body($stored));
        $this->assertSame($results[0]['status'] === 204 ? $results[0]['etag'] : $results[1]['etag'], $stored->headers->get('ETag'));
    }

    public function test_two_creates_of_the_same_uri_with_if_none_match_one_wins_and_the_other_gets_412(): void
    {
        $results = $this->together([
            $this->job('PUT', 'new.ics', $this->event('new', 'Title A'), ['If-None-Match' => '*']),
            $this->job('PUT', 'new.ics', $this->event('new', 'Title B'), ['If-None-Match' => '*']),
        ]);

        $this->assertSame([201, 412], $this->statuses($results), json_encode($results));
        $this->assertSame(1, DB::table('calendarobjects')->where('uri', 'new.ics')->count());
    }

    public function test_two_creates_of_the_same_uri_without_a_precondition_the_second_replaces_the_first(): void
    {
        $results = $this->together([
            $this->job('PUT', 'plain.ics', $this->event('plain', 'Title A')),
            $this->job('PUT', 'plain.ics', $this->event('plain', 'Title B')),
        ]);

        $this->assertSame([201, 204], $this->statuses($results), json_encode($results));
        $this->assertSame(1, DB::table('calendarobjects')->where('uri', 'plain.ics')->count());

        $last = $results[0]['status'] === 204 ? 'Title A' : 'Title B';
        $this->assertStringContainsString('SUMMARY:'.$last, $this->body($this->fetch('plain.ics')->assertOk()));
    }

    public function test_a_delete_and_an_update_of_the_same_item_do_not_both_succeed(): void
    {
        $etag = $this->store('gone.ics', $this->event('gone', 'Original'))->assertCreated()->headers->get('ETag');

        $results = $this->together([
            $this->job('DELETE', 'gone.ics', null, ['If-Match' => (string) $etag]),
            $this->job('PUT', 'gone.ics', $this->event('gone', 'Edited'), ['If-Match' => (string) $etag]),
        ]);

        $statuses = $this->statuses($results);
        $this->assertContains($statuses, [[204, 412]], json_encode($results));
    }

    /**
     * Issue #3: parallel uploads of different items on SQLite got
     * "database is locked" (500).
     */
    public function test_parallel_uploads_of_different_items_all_succeed(): void
    {
        $jobs = [];

        for ($n = 1; $n <= 8; $n++) {
            $jobs[] = $this->job('PUT', 'item-'.$n.'.ics', $this->event('item-'.$n, 'Item '.$n), [], 0);
        }

        $results = $this->together($jobs);

        $this->assertSame(array_fill(0, 8, 201), array_column($results, 'status'), json_encode($results));
        $this->assertSame(8, DB::table('calendarobjects')->where('uri', 'like', 'item-%')->count());
    }

    /**
     * PR 17: another connection holds the SQLite write lock longer than
     * busy_timeout. The write is retried and succeeds once the lock is
     * released; while it is held for good, the client gets 503.
     */
    public function test_a_write_behind_a_held_sqlite_lock_is_retried_then_answered_503(): void
    {
        if ($this->file === null) {
            $this->markTestSkipped('Holds the SQLite database lock.');
        }

        $env = ['DB_BUSY_TIMEOUT' => '100'];

        $holder = $this->lockDatabase();
        $released = $this->together([$this->job('PUT', 'late.ics', $this->event('late', 'Late'), [], 0)], $env, function (int $start) use ($holder): void {
            usleep(max(0, $start + 400_000 - (int) (microtime(true) * 1_000_000)));
            $holder->exec('COMMIT');
        });
        $this->assertSame([201], array_column($released, 'status'), json_encode($released));

        $holder = $this->lockDatabase();
        $held = $this->together([$this->job('PUT', 'never.ics', $this->event('never', 'Never'), [], 0)], $env);
        $holder->exec('COMMIT');

        $this->assertSame([503], array_column($held, 'status'), json_encode($held));
        $this->assertSame('5', $held[0]['retry_after']);
        $this->assertSame(0, DB::table('calendarobjects')->where('uri', 'never.ics')->count());
        $this->assertSame(1, DB::table('calendarobjects')->where('uri', 'late.ics')->count());
    }

    /**
     * The owner's path and the sharee's path are one calendar. One If-Match
     * wins; the other is 412. On PostgreSQL that only holds if both paths
     * take the lock of calendars.id.
     */
    public function test_the_same_if_match_through_the_owner_and_the_sharee_one_wins(): void
    {
        $bob = app(UserService::class)->create(['username' => 'bob', 'display_name' => 'Bob', 'email' => 'bob@example.com']);
        $bobPassword = app(AppPasswordService::class)->issue($bob, 'phone')['token'];
        $share = app(SharingService::class)->share(
            CalendarInstance::query()->where('principaluri', 'principals/ada')->where('uri', 'default')->where('access', 1)->firstOrFail(),
            $bob,
            SharingService::READ_WRITE,
        );

        $etag = $this->store('shared.ics', $this->event('shared', 'Original'))->assertCreated()->headers->get('ETag');
        $this->assertNotNull($etag);

        $headers = ['If-Match' => $etag, 'Content-Type' => 'text/calendar'];
        $results = $this->together([
            [
                'method' => 'PUT',
                'uri' => '/dav/calendars/ada/default/shared.ics',
                'user' => 'ada',
                'password' => $this->password,
                'headers' => $headers,
                'body' => $this->event('shared', 'Title A'),
                'hold_ms' => self::HOLD_MS,
            ],
            [
                'method' => 'PUT',
                'uri' => '/dav/calendars/bob/'.$share->uri.'/shared.ics',
                'user' => 'bob',
                'password' => $bobPassword,
                'headers' => $headers,
                'body' => $this->event('shared', 'Title B'),
                'hold_ms' => self::HOLD_MS,
            ],
        ]);

        $this->assertSame([204, 412], $this->statuses($results), json_encode($results));
        $winner = $results[0]['status'] === 204 ? 'Title A' : 'Title B';
        $this->assertStringContainsString('SUMMARY:'.$winner, $this->body($this->fetch('shared.ics')->assertOk()));
        $this->assertSame(1, DB::table('calendarobjects')->where('uri', 'shared.ics')->count());
    }

    /**
     * The same race for a contact: the owner's book and the sharee's copy of
     * it are one address book, so one If-Match wins.
     */
    public function test_the_same_if_match_on_a_contact_through_the_owner_and_the_sharee_one_wins(): void
    {
        $bob = app(UserService::class)->create(['username' => 'bob', 'display_name' => 'Bob', 'email' => 'bob@example.com']);
        $bobPassword = app(AppPasswordService::class)->issue($bob, 'phone')['token'];
        $share = app(SharingService::class)->share(
            AddressBook::query()->where('principaluri', 'principals/ada')->where('uri', 'default')->firstOrFail(),
            $bob,
            SharingService::READ_WRITE,
        );

        $created = $this->call('PUT', '/dav/addressbooks/ada/default/shared.vcf', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Basic '.base64_encode('ada:'.$this->password),
            'CONTENT_TYPE' => 'text/vcard',
        ], $this->contact('shared', 'Original'));
        $created->assertCreated();
        $etag = $created->headers->get('ETag');
        $this->assertNotNull($etag);

        $headers = ['If-Match' => $etag, 'Content-Type' => 'text/vcard'];
        $results = $this->together([
            [
                'method' => 'PUT',
                'uri' => '/dav/addressbooks/ada/default/shared.vcf',
                'user' => 'ada',
                'password' => $this->password,
                'headers' => $headers,
                'body' => $this->contact('shared', 'Name A'),
                'hold_ms' => self::HOLD_MS,
            ],
            [
                'method' => 'PUT',
                'uri' => '/dav/addressbooks/bob/'.$share->uri.'/shared.vcf',
                'user' => 'bob',
                'password' => $bobPassword,
                'headers' => $headers,
                'body' => $this->contact('shared', 'Name B'),
                'hold_ms' => self::HOLD_MS,
            ],
        ]);

        $this->assertSame([204, 412], $this->statuses($results), json_encode($results));
        $winner = $results[0]['status'] === 204 ? 'Name A' : 'Name B';
        $stored = $this->call('GET', '/dav/addressbooks/ada/default/shared.vcf', [], [], [], ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode('ada:'.$this->password)]);
        $this->assertStringContainsString('FN:'.$winner, $this->body($stored->assertOk()));
        $this->assertSame(1, DB::table('cards')->where('uri', 'shared.vcf')->count());
    }

    private function contact(string $uid, string $name): string
    {
        return "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:$uid\r\nFN:$name\r\nN:$name;;;;\r\nEND:VCARD\r\n";
    }

    private function lockDatabase(): \PDO
    {
        $pdo = new \PDO('sqlite:'.$this->file);
        $pdo->exec('BEGIN IMMEDIATE');

        return $pdo;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function job(string $method, string $name, ?string $body, array $headers = [], int $hold = self::HOLD_MS): array
    {
        if ($body !== null) {
            $headers['Content-Type'] = 'text/calendar';
        }

        return [
            'method' => $method,
            'uri' => '/dav/calendars/ada/default/'.$name,
            'user' => 'ada',
            'password' => $this->password,
            'headers' => $headers,
            'body' => $body,
            'hold_ms' => $hold,
        ];
    }

    /**
     * Starts one worker per job and has them send at the same moment.
     *
     * @param  list<array<string, mixed>>  $jobs
     * @param  array<string, string>  $extraEnv
     * @param  (callable(int): void)|null  $during  runs once the workers are started, with their start time
     * @return list<array{status: int, etag: string|null, retry_after: string|null}>
     */
    private function together(array $jobs, array $extraEnv = [], ?callable $during = null): array
    {
        $start = (int) (microtime(true) * 1_000_000) + 2_500_000;
        $env = array_merge(getenv(), [
            'DB_CONNECTION' => $this->driver,
            'DB_DATABASE' => $this->file ?? (string) $this->database,
        ], $extraEnv);
        $processes = [];

        foreach ($jobs as $job) {
            $job['start'] = $start;
            $process = proc_open(
                [PHP_BINARY, base_path('tests/Support/dav-worker.php'), json_encode($job, JSON_THROW_ON_ERROR)],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                base_path(),
                $env,
            );
            $this->assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        if ($during !== null) {
            $during($start);
        }

        $results = [];

        foreach ($processes as [$process, $pipes]) {
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            $decoded = json_decode($out, true);
            $this->assertIsArray($decoded, 'Worker failed: '.$out.$err);
            $results[] = ['status' => (int) $decoded['status'], 'etag' => $decoded['etag'] ?? null, 'retry_after' => $decoded['retry_after'] ?? null];
        }

        return $results;
    }

    /**
     * @param  list<array{status: int, etag: string|null, retry_after: string|null}>  $results
     * @return list<int>
     */
    private function statuses(array $results): array
    {
        $statuses = array_column($results, 'status');
        sort($statuses);

        return $statuses;
    }

    private function store(string $name, string $body): TestResponse
    {
        return $this->dav('PUT', $name, ['CONTENT_TYPE' => 'text/calendar'], $body);
    }

    private function fetch(string $name): TestResponse
    {
        return $this->dav('GET', $name);
    }

    /**
     * @param  array<string, string>  $server
     */
    private function dav(string $method, string $name, array $server = [], ?string $body = null): TestResponse
    {
        $server['HTTP_AUTHORIZATION'] = 'Basic '.base64_encode('ada:'.$this->password);

        return $this->call($method, '/dav/calendars/ada/default/'.$name, [], [], [], $server, $body);
    }

    private function event(string $uid, string $summary): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//NexusDAV//test//EN\r\nBEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260101T100000Z\r\nSUMMARY:$summary\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    private function body(TestResponse $response): string
    {
        return $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : (string) $response->getContent();
    }
}
