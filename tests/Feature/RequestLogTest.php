<?php

namespace Tests\Feature;

use App\Filament\Widgets\Diagnostics\DavErrorsTable;
use App\Logging\ContainerLineFormatter;
use App\Models\DiagnosticEvent;
use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\UserService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

/**
 * PR 8: one log line per request, with an ID shared by the response header,
 * the other log lines of the request and the Diagnostics rows.
 */
class RequestLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_request_gets_an_id_and_one_log_line(): void
    {
        $ada = $this->makeUser('ada');
        $token = app(AppPasswordService::class)->issue($ada, 'phone')['token'];
        $logged = $this->captureRequestLines();

        $first = $this->dav('PROPFIND', '/dav/', 'ada', $token)->assertStatus(207);
        $second = $this->dav('PROPFIND', '/dav/', 'ada', $token)->assertStatus(207);

        $id = $this->requestId($first);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $id);
        $this->assertNotSame($id, $this->requestId($second));

        $this->assertCount(2, $logged->lines);
        $line = $logged->lines[0];
        $this->assertSame('PROPFIND', $line['method']);
        // The test client drops the trailing slash; a real request keeps it.
        $this->assertSame('/dav', $line['path']);
        $this->assertSame(207, $line['status']);
        $this->assertSame('ada', $line['user']);
        $this->assertSame('127.0.0.1', $line['ip']);
        $this->assertIsFloat($line['duration_ms']);
        $this->assertStringNotContainsString(explode('.', $token, 2)[1], (string) json_encode($logged->lines));
    }

    public function test_the_request_line_level_follows_the_status(): void
    {
        $ada = $this->makeUser('ada');
        $token = app(AppPasswordService::class)->issue($ada, 'phone')['token'];
        $handler = $this->captureRecords();

        $this->dav('PROPFIND', '/dav/', 'ada', $token)->assertStatus(207);
        $this->call('PROPFIND', '/dav/')->assertStatus(401);
        $this->dav('PROPFIND', '/dav/calendars/ada/missing/', 'ada', $token)->assertStatus(404);
        $this->app['router']->get('/test-failure', fn () => abort(503));
        $this->get('/test-failure')->assertStatus(503);

        $levels = [];

        foreach ($handler->getRecords() as $record) {
            if ($record->message === 'Request') {
                $levels[$record->context['status']] = $record->level->getName();
            }
        }

        $this->assertSame([207 => 'INFO', 401 => 'INFO', 404 => 'WARNING', 503 => 'ERROR'], $levels);
    }

    /**
     * Log viewers such as Dozzle read the level from a word at the start of
     * the line, after the timestamp.
     */
    public function test_the_container_line_starts_with_the_time_and_the_level(): void
    {
        $formatter = new ContainerLineFormatter;
        $record = new LogRecord(new \DateTimeImmutable('2026-11-04 14:04:44'), 'production', Level::Error, 'DAV request failed',
            ['exception' => new \RuntimeException('database is locked')], ['request_id' => 'fc2f93e8491d35f3']);

        $line = $formatter->format($record);

        $this->assertStringStartsWith('2026-11-04 14:04:44 ERROR DAV request failed {"exception":', $line);
        $this->assertStringContainsString('{"request_id":"fc2f93e8491d35f3"}', $line);
        $this->assertStringContainsString('[stacktrace]', $line, 'The stack trace stays with the error.');
        $this->assertStringNotContainsString('production', strtok($line, "\n"));
        $this->assertSame(ContainerLineFormatter::class, config('logging.channels.stderr.formatter'));
    }

    public function test_other_log_lines_of_the_request_carry_its_id(): void
    {
        $this->makeUser('ada');
        $handler = $this->captureRecords();

        $response = $this->dav('PROPFIND', '/dav/', 'ada', 'nope.not-a-real-secret')->assertStatus(401);
        $id = $this->requestId($response);

        $messages = [];

        foreach ($handler->getRecords() as $record) {
            $messages[$record->message] = $record->extra['request_id'] ?? null;
        }

        $this->assertSame($id, $messages['Authentication failed'] ?? null);
        $this->assertSame($id, $messages['Request'] ?? null);
        $this->assertStringNotContainsString('not-a-real-secret', (string) json_encode(array_map(
            fn ($record): array => $record->toArray(),
            $handler->getRecords(),
        )));
    }

    public function test_a_healthy_healthcheck_is_not_logged(): void
    {
        $logged = $this->captureRequestLines();

        $response = $this->get('/health')->assertOk();

        $this->requestId($response);
        $this->assertSame([], $logged->lines);
    }

    public function test_panel_requests_name_the_signed_in_admin(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['nexusdav.env_set' => []]);
        $logged = $this->captureRequestLines();

        $this->get('/admin')->assertRedirect('/admin/login');
        $this->actingAs($this->makeUser('root', true));
        $this->get('/admin')->assertOk();

        $this->assertSame([302, 200], array_column($logged->lines, 'status'));
        $this->assertSame([null, 'root'], array_column($logged->lines, 'user'));
    }

    public function test_diagnostics_rows_keep_the_request_id_of_their_first_occurrence(): void
    {
        $ada = $this->makeUser('ada');
        $token = app(AppPasswordService::class)->issue($ada, 'phone')['token'];

        $missing = $this->requestId($this->dav('PROPFIND', '/dav/calendars/ada/nothing-here/', 'ada', $token)->assertNotFound());
        $this->dav('PROPFIND', '/dav/calendars/ada/nothing-here/', 'ada', $token)->assertNotFound();
        $refused = $this->requestId($this->dav('PROPFIND', '/dav/', 'ada', 'nope.not-a-real-secret')->assertStatus(401));

        $error = DiagnosticEvent::query()->where('kind', DiagnosticEvent::KIND_HTTP_ERROR)->sole();
        $this->assertSame($missing, $error->request_id);
        $this->assertSame(2, $error->repeat_count);
        $this->assertSame($refused, DiagnosticEvent::query()->where('kind', DiagnosticEvent::KIND_AUTH_FAILED)->sole()->request_id);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->makeUser('root', true));
        Livewire::test(DavErrorsTable::class)->assertSee($missing)->assertSee($refused);
    }

    private function makeUser(string $username, bool $admin = false): User
    {
        return app(UserService::class)->create([
            'username' => $username,
            'display_name' => ucfirst($username),
            'email' => $username.'@example.com',
            'is_admin' => $admin,
            'password' => 'correct-horse-battery',
        ]);
    }

    private function dav(string $method, string $uri, string $username, string $password): TestResponse
    {
        return $this->call($method, $uri, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Basic '.base64_encode($username.':'.$password),
            'HTTP_DEPTH' => '0',
        ]);
    }

    private function requestId(TestResponse $response): string
    {
        $id = $response->headers->get('X-Request-Id');
        $this->assertIsString($id);
        $this->assertNotSame('', $id);

        return $id;
    }

    private function captureRequestLines(): object
    {
        $logged = new \stdClass;
        $logged->lines = [];

        Log::listen(function (MessageLogged $event) use ($logged): void {
            if ($event->message === 'Request') {
                $logged->lines[] = $event->context;
            }
        });

        return $logged;
    }

    /**
     * Sends the default channel to a Monolog TestHandler, so the records
     * include the extra fields that Laravel's Context adds.
     */
    private function captureRecords(): TestHandler
    {
        config([
            'logging.channels.capture' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'level' => 'debug'],
            'logging.default' => 'capture',
        ]);
        Log::forgetChannel('capture');

        $handler = Log::channel('capture')->getLogger()->getHandlers()[0];
        $this->assertInstanceOf(TestHandler::class, $handler);

        return $handler;
    }
}
