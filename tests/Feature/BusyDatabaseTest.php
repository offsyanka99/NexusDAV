<?php

namespace Tests\Feature;

use App\Events\DavServerCreated;
use App\Models\DiagnosticEvent;
use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\UserService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use PDOException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;
use Throwable;

/**
 * PR 17: a busy database is retried, then answered 503 with Retry-After,
 * never 500. The errors are thrown from a listener, so both databases follow
 * one path; ConcurrentWritesTest holds a real SQLite lock.
 */
class BusyDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private User $ada;

    private string $token;

    private int $attempts = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ada = app(UserService::class)->create(['username' => 'ada', 'display_name' => 'Ada', 'email' => 'ada@example.com']);
        $this->token = app(AppPasswordService::class)->issue($this->ada, 'phone')['token'];
    }

    public function test_a_write_that_finds_the_database_busy_is_retried_and_succeeds(): void
    {
        $this->failFirst(2, 'PUT', $this->sqliteBusy());

        $this->putEvent('retried.ics')->assertCreated();

        $this->assertSame(3, $this->attempts);
        $this->assertSame(1, DB::table('calendarobjects')->where('uri', 'retried.ics')->count());
    }

    public function test_after_three_retries_the_client_gets_503_with_retry_after(): void
    {
        $this->failFirst(PHP_INT_MAX, 'PUT', $this->sqliteBusy());

        $response = $this->putEvent('busy.ics')->assertStatus(503);

        $this->assertSame(4, $this->attempts);
        $this->assertSame('5', $response->headers->get('Retry-After'));
        $body = $this->body($response);
        $this->assertStringContainsString('The server is busy', $body);
        $this->assertStringContainsString((string) $response->headers->get('X-Request-Id'), $body);
        $this->assertStringNotContainsString('locked', $body);
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertSame(0, DB::table('calendarobjects')->where('uri', 'busy.ics')->count());

        $event = DiagnosticEvent::query()->where('kind', DiagnosticEvent::KIND_HTTP_ERROR)->sole();
        $this->assertSame(503, $event->status);
        $this->assertSame('ada', $event->username);
        $this->assertSame($response->headers->get('X-Request-Id'), $event->request_id);
    }

    public function test_postgresql_serialization_failures_deadlocks_and_lock_timeouts_are_retried(): void
    {
        foreach (['40001', '40P01', '55P03'] as $state) {
            $this->attempts = 0;
            Event::forget(DavServerCreated::class);
            $this->failFirst(1, 'PUT', $this->postgresError($state));

            $this->putEvent('pg-'.$state.'.ics')->assertCreated();
            $this->assertSame(2, $this->attempts, $state);
        }
    }

    public function test_a_busy_database_during_a_read_is_answered_503_at_once(): void
    {
        $this->failFirst(PHP_INT_MAX, 'PROPFIND', $this->sqliteBusy());

        $response = $this->dav('PROPFIND', '/dav/calendars/ada/default/', ['HTTP_DEPTH' => '0'])->assertStatus(503);

        $this->assertSame(1, $this->attempts);
        $this->assertSame('5', $response->headers->get('Retry-After'));
    }

    public function test_other_server_errors_are_500_without_the_exception_text(): void
    {
        $this->failFirst(PHP_INT_MAX, 'PUT', new RuntimeException('secret detail /data/database/nexusdav.sqlite'));

        $response = $this->putEvent('broken.ics')->assertStatus(500);

        $this->assertSame(1, $this->attempts);
        $body = $this->body($response);
        $this->assertStringContainsString('Internal server error', $body);
        $this->assertStringContainsString((string) $response->headers->get('X-Request-Id'), $body);
        $this->assertStringNotContainsString('secret detail', $body);
        $this->assertStringNotContainsString('RuntimeException', $body);
    }

    public function test_a_busy_database_does_not_fail_the_sign_in_when_recording_last_use(): void
    {
        DB::listen(function ($query): void {
            if (str_contains($query->sql, 'update "app_passwords"')) {
                throw $this->sqliteBusy();
            }
        });

        $this->putEvent('signed-in.ics')->assertCreated();
    }

    public function test_dav_client_errors_keep_their_message(): void
    {
        $this->dav('PUT', '/dav/calendars/ada/default/bad.ics', ['CONTENT_TYPE' => 'text/calendar'], 'not a calendar')
            ->assertStatus(415);
    }

    private function failFirst(int $times, string $method, Throwable $exception): void
    {
        Event::listen(DavServerCreated::class, function (DavServerCreated $event) use ($times, $method, $exception): void {
            $event->server->on('beforeMethod:'.$method, function () use ($times, $exception): void {
                $this->attempts++;

                if ($this->attempts <= $times) {
                    throw $exception;
                }
            }, 120);
        });
    }

    private function sqliteBusy(): QueryException
    {
        return new QueryException('sqlite', 'insert into "calendarobjects" ("uri") values (?)', ['x.ics'], new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'));
    }

    private function postgresError(string $state): QueryException
    {
        $pdo = new class($state) extends PDOException
        {
            public function __construct(string $state)
            {
                parent::__construct('SQLSTATE['.$state.']: could not serialize access');
                $this->code = $state;
            }
        };

        return new QueryException('pgsql', 'insert into calendarobjects', [], $pdo);
    }

    private function putEvent(string $name): TestResponse
    {
        $event = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//NexusDAV//test//EN\r\nBEGIN:VEVENT\r\nUID:$name\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260101T100000Z\r\nSUMMARY:Busy\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        return $this->dav('PUT', '/dav/calendars/ada/default/'.$name, ['CONTENT_TYPE' => 'text/calendar'], $event);
    }

    /**
     * @param  array<string, string>  $server
     */
    private function dav(string $method, string $uri, array $server = [], ?string $body = null): TestResponse
    {
        $server['HTTP_AUTHORIZATION'] = 'Basic '.base64_encode('ada:'.$this->token);

        return $this->call($method, $uri, [], [], [], $server, $body);
    }

    private function body(TestResponse $response): string
    {
        return $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : (string) $response->getContent();
    }
}
