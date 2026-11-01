<?php

namespace Tests\Feature;

use App\Filament\Auth\Login;
use App\Models\DiagnosticEvent;
use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\Dav\DavAuthBackend;
use App\Services\DiagnosticEventWriter;
use App\Services\UserService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

class BruteForceProtectionTest extends TestCase
{
    use RefreshDatabase;

    private string $clientIp = '127.0.0.1';

    private bool $clientIpBound = false;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_login_throttles_by_ip_and_username(): void
    {
        config(['nexusdav.rate_limit.max_attempts' => 3]);

        $locked = User::factory()->create([
            'email' => 'locked@example.com',
            'password' => 'secret-password',
            'is_admin' => true,
            'is_active' => true,
        ]);
        $other = User::factory()->create([
            'email' => 'other@example.com',
            'password' => 'secret-password',
            'is_admin' => true,
            'is_active' => true,
        ]);

        $this->useClientIp('203.0.113.10');
        $this->failAdminLogin($locked->email, 3);
        $this->assertAdminThrottled($locked->email, 'secret-password');

        $this->useClientIp('203.0.113.20');
        Livewire::test(Login::class)
            ->fillForm([
                'email' => $locked->email,
                'password' => 'secret-password',
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect();
        $this->assertAuthenticatedAs($locked);

        auth()->logout();

        $this->useClientIp('203.0.113.10');
        Livewire::test(Login::class)
            ->fillForm([
                'email' => $other->email,
                'password' => 'secret-password',
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect();
        $this->assertAuthenticatedAs($other);
    }

    public function test_admin_failures_are_logged_and_collapsed_without_the_password(): void
    {
        $admin = User::factory()->create([
            'username' => 'ada',
            'email' => 'ada@example.com',
            'password' => 'secret-password',
            'is_admin' => true,
            'is_active' => true,
        ]);
        $password = 'wrong-password';
        $logged = $this->captureLogs();

        $this->failAdminLogin($admin->email, 2);

        $rows = DiagnosticEvent::query()->get();
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertInstanceOf(DiagnosticEvent::class, $row);
        $this->assertSame(DiagnosticEvent::SOURCE_ADMIN, $row->source);
        $this->assertSame(DiagnosticEvent::KIND_AUTH_FAILED, $row->kind);
        $this->assertSame('ada', $row->username);
        $this->assertSame('bad password', $row->reason);
        $this->assertSame('/admin/login', $row->path);
        $this->assertSame(422, $row->status);
        $this->assertSame(2, $row->repeat_count);
        $this->assertNull($row->public_id);
        $this->assertStringNotContainsString($password, (string) json_encode($row->getAttributes()));

        $this->assertCount(2, $logged->lines);
        $encoded = (string) json_encode($logged->lines);
        $this->assertStringNotContainsString($password, $encoded);
        $this->assertStringContainsString('bad password', $encoded);
        $this->assertSame(
            ['source', 'ip', 'username', 'path', 'status', 'reason'],
            array_keys($logged->lines[0]['context']),
        );
    }

    public function test_admin_panel_refusal_is_not_recorded_as_a_bad_password(): void
    {
        $disabled = User::factory()->create([
            'username' => 'ada',
            'email' => 'ada@example.com',
            'password' => 'secret-password',
            'is_admin' => true,
            'is_active' => false,
        ]);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $disabled->email,
                'password' => 'secret-password',
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $row = DiagnosticEvent::query()->first();
        $this->assertInstanceOf(DiagnosticEvent::class, $row);
        $this->assertSame('panel refused', $row->reason);
        $this->assertSame('ada', $row->username);
        $this->assertStringNotContainsString('secret-password', (string) json_encode($row->getAttributes()));

        DiagnosticEvent::query()->delete();

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $disabled->email,
                'password' => 'wrong-password',
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $wrong = DiagnosticEvent::query()->first();
        $this->assertInstanceOf(DiagnosticEvent::class, $wrong);
        $this->assertSame('bad password', $wrong->reason);
        $this->assertSame('ada', $wrong->username);
        $this->assertStringNotContainsString('wrong-password', (string) json_encode($wrong->getAttributes()));

        $member = User::factory()->create([
            'username' => 'beau',
            'email' => 'beau@example.com',
            'password' => 'secret-password',
            'is_admin' => false,
            'is_active' => true,
        ]);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $member->email,
                'password' => 'secret-password',
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $refused = DiagnosticEvent::query()->where('username', 'beau')->first();
        $this->assertInstanceOf(DiagnosticEvent::class, $refused);
        $this->assertSame('panel refused', $refused->reason);
    }

    public function test_unknown_admin_login_is_recorded_as_unknown_user(): void
    {
        Livewire::test(Login::class)
            ->fillForm([
                'email' => 'missing@example.com',
                'password' => 'not-a-real-password',
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $row = DiagnosticEvent::query()->first();
        $this->assertInstanceOf(DiagnosticEvent::class, $row);
        $this->assertSame('unknown user', $row->reason);
        $this->assertSame('missing@example.com', $row->username);
        $this->assertStringNotContainsString('not-a-real-password', (string) json_encode($row->getAttributes()));
    }

    public function test_dav_bad_password_is_recorded_once_and_repeats_collapse(): void
    {
        $user = $this->makeUser('ada', 'ada@example.com');
        $issued = app(AppPasswordService::class)->issue($user, 'phone');
        [$publicId, $secret] = explode('.', $issued['token'], 2);
        $logged = $this->captureLogs();

        $first = $this->dav('PROPFIND', '/dav/?leak=1', 'ada', $publicId.'.not-the-secret', '198.51.100.10');
        $second = $this->dav('PROPFIND', '/dav/?other=1', 'Ada', $publicId.'.still-wrong', '198.51.100.10');

        $this->assertPlainUnauthorized($first, $secret, 'not-the-secret');
        $this->assertPlainUnauthorized($second, $secret, 'still-wrong');
        $this->assertSame(DavAuthBackend::BAD_SECRET, app(DavAuthBackend::class)->failureReason());

        $rows = DiagnosticEvent::query()->get();
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertInstanceOf(DiagnosticEvent::class, $row);
        $this->assertSame(DiagnosticEvent::SOURCE_DAV, $row->source);
        $this->assertSame(DiagnosticEvent::KIND_AUTH_FAILED, $row->kind);
        $this->assertSame(DavAuthBackend::BAD_SECRET, $row->reason);
        $this->assertSame('ada', $row->username);
        $this->assertSame($publicId, $row->public_id);
        $this->assertSame('198.51.100.10', $row->ip);
        $this->assertSame(401, $row->status);
        $this->assertSame(2, $row->repeat_count);
        $this->assertSame('PROPFIND', $row->method);
        $this->assertStringStartsWith('/dav', $row->path);
        $this->assertStringNotContainsString('leak', $row->path);
        $this->assertStringNotContainsString('?', $row->path);

        $stored = (string) json_encode($row->getAttributes());
        $this->assertStringNotContainsString($secret, $stored);
        $this->assertStringNotContainsString('not-the-secret', $stored);
        $this->assertStringNotContainsString('still-wrong', $stored);

        $this->assertCount(2, $logged->lines);
        $encoded = (string) json_encode($logged->lines);
        $this->assertStringNotContainsString($secret, $encoded);
        $this->assertStringNotContainsString('not-the-secret', $encoded);
        $this->assertStringContainsString(DavAuthBackend::BAD_SECRET, $encoded);
        $this->assertSame(
            ['source', 'ip', 'username', 'path', 'status', 'reason'],
            array_keys($logged->lines[0]['context']),
        );
    }

    public function test_dav_request_without_credentials_is_not_recorded_or_throttled(): void
    {
        config(['nexusdav.rate_limit.max_attempts' => 3]);

        $user = $this->makeUser('ada', 'ada@example.com');
        $token = app(AppPasswordService::class)->issue($user, 'phone')['token'];

        foreach (range(1, 5) as $ignored) {
            $response = $this->dav('PROPFIND', '/dav/', null, null, '198.51.100.20');
            $response->assertUnauthorized();
            $this->assertNull(app(DavAuthBackend::class)->failureReason());
        }

        $this->assertSame(0, DiagnosticEvent::query()->count());

        $this->dav('PROPFIND', '/dav/', 'ada', $token, '198.51.100.20', $this->propfind())
            ->assertStatus(207);
    }

    public function test_dav_throttle_is_per_ip_and_username_and_does_not_record_a_second_401(): void
    {
        config(['nexusdav.rate_limit.max_attempts' => 3]);

        $ada = $this->makeUser('ada', 'ada@example.com');
        $bob = $this->makeUser('bob', 'bob@example.com');
        $adaToken = app(AppPasswordService::class)->issue($ada, 'phone')['token'];
        $bobToken = app(AppPasswordService::class)->issue($bob, 'phone')['token'];
        [$publicId, $secret] = explode('.', $adaToken, 2);

        foreach (range(1, 3) as $ignored) {
            $this->dav('PROPFIND', '/dav/', 'ada', $publicId.'.not-the-secret', '203.0.113.10')
                ->assertUnauthorized();
        }

        $throttled = $this->dav('PROPFIND', '/dav/', 'ada', $adaToken, '203.0.113.10', $this->propfind());
        $this->assertPlainUnauthorized($throttled, $secret, $adaToken);
        $this->assertNull(app(DavAuthBackend::class)->failureReason());
        $this->assertSame(3, DiagnosticEvent::query()->where('username', 'ada')->value('repeat_count'));
        $this->assertSame(1, DiagnosticEvent::query()->where('kind', DiagnosticEvent::KIND_AUTH_FAILED)->count());
        $this->assertSame(0, DiagnosticEvent::query()->where('kind', DiagnosticEvent::KIND_HTTP_ERROR)->count());

        $this->dav('PROPFIND', '/dav/', 'ada', $adaToken, '203.0.113.20', $this->propfind())
            ->assertStatus(207);

        $this->dav('PROPFIND', '/dav/', 'bob', $bobToken, '203.0.113.10', $this->propfind())
            ->assertStatus(207);
    }

    public function test_spoofed_forwarded_for_stays_on_the_peer_address(): void
    {
        config(['nexusdav.rate_limit.max_attempts' => 3]);

        $user = $this->makeUser('ada', 'ada@example.com');
        $token = app(AppPasswordService::class)->issue($user, 'phone')['token'];
        [$publicId, $secret] = explode('.', $token, 2);

        foreach (['203.0.113.77', '203.0.113.78', '198.51.100.99'] as $spoofed) {
            $response = $this->dav('PROPFIND', '/dav/', 'ada', $publicId.'.not-the-secret', '198.51.100.10', null, [
                'HTTP_X_FORWARDED_FOR' => $spoofed,
            ]);
            $response->assertUnauthorized();
            $this->assertStringNotContainsString($spoofed, (string) $response->getContent());
            $this->assertStringNotContainsString($secret, (string) $response->getContent());
        }

        $row = DiagnosticEvent::query()->first();
        $this->assertInstanceOf(DiagnosticEvent::class, $row);
        $this->assertSame('198.51.100.10', $row->ip);
        $this->assertSame(3, $row->repeat_count);
        $this->assertStringNotContainsString('203.0.113', (string) json_encode($row->getAttributes()));

        $locked = $this->dav('PROPFIND', '/dav/', 'ada', $token, '198.51.100.10', $this->propfind(), [
            'HTTP_X_FORWARDED_FOR' => '203.0.113.1',
        ]);
        $locked->assertUnauthorized();
        $this->assertNull(app(DavAuthBackend::class)->failureReason());
        $this->assertSame(3, (int) DiagnosticEvent::query()->value('repeat_count'));

        $this->dav('PROPFIND', '/dav/', 'ada', $token, '203.0.113.77', $this->propfind())
            ->assertStatus(207);
    }

    public function test_explicit_proxy_uses_the_forwarded_client_and_a_stranger_cannot_spoof_it(): void
    {
        config([
            'nexusdav.rate_limit.max_attempts' => 3,
            'nexusdav.trusted_proxies' => '10.0.0.1',
        ]);

        $user = $this->makeUser('ada', 'ada@example.com');
        $token = app(AppPasswordService::class)->issue($user, 'phone')['token'];
        [$publicId] = explode('.', $token, 2);

        foreach (range(1, 3) as $ignored) {
            $this->dav('PROPFIND', '/dav/', 'ada', $publicId.'.not-the-secret', '10.0.0.1', null, [
                'HTTP_X_FORWARDED_FOR' => '203.0.113.50',
            ])->assertUnauthorized();
        }

        $row = DiagnosticEvent::query()->first();
        $this->assertInstanceOf(DiagnosticEvent::class, $row);
        $this->assertSame('203.0.113.50', $row->ip);

        $this->dav('PROPFIND', '/dav/', 'ada', $token, '10.0.0.1', $this->propfind(), [
            'HTTP_X_FORWARDED_FOR' => '203.0.113.50',
        ])->assertUnauthorized();

        $this->dav('PROPFIND', '/dav/', 'ada', $token, '10.0.0.1', $this->propfind(), [
            'HTTP_X_FORWARDED_FOR' => '203.0.113.60',
        ])->assertStatus(207);

        $this->dav('PROPFIND', '/dav/', 'ada', $token, '198.51.100.8', $this->propfind(), [
            'HTTP_X_FORWARDED_FOR' => '203.0.113.50',
        ])->assertStatus(207);
    }

    public function test_dav_email_and_username_share_one_lock_without_a_second_token_lookup(): void
    {
        config(['nexusdav.rate_limit.max_attempts' => 3]);

        $user = $this->makeUser('ada', 'ada@example.com');
        $token = app(AppPasswordService::class)->issue($user, 'phone')['token'];
        [$publicId, $secret] = explode('.', $token, 2);

        foreach (range(1, 3) as $ignored) {
            $this->dav('PROPFIND', '/dav/', 'ada', $publicId.'.not-the-secret', '198.51.100.40')
                ->assertUnauthorized();
        }

        $emailQueries = $this->logged(function () use ($token, $secret): void {
            $response = $this->dav('PROPFIND', '/dav/', 'ada@example.com', $token, '198.51.100.40', $this->propfind());
            $response->assertUnauthorized();
            $this->assertStringNotContainsString($secret, (string) $response->getContent());
            $this->assertNull(app(DavAuthBackend::class)->failureReason());
        });
        $this->assertNoAppPasswordQuery($emailQueries, $secret);

        foreach (range(1, 3) as $ignored) {
            $this->dav('PROPFIND', '/dav/', 'ada@example.com', $publicId.'.not-the-secret', '198.51.100.41')
                ->assertUnauthorized();
        }

        $usernameQueries = $this->logged(function () use ($token, $secret): void {
            $response = $this->dav('PROPFIND', '/dav/', 'ada', $token, '198.51.100.41', $this->propfind());
            $response->assertUnauthorized();
            $this->assertStringNotContainsString($secret, (string) $response->getContent());
            $this->assertNull(app(DavAuthBackend::class)->failureReason());
        });
        $this->assertNoAppPasswordQuery($usernameQueries, $secret);

        foreach (range(1, 3) as $ignored) {
            $this->dav('PROPFIND', '/dav/', 'nobody', 'missing-public.missing-secret', '198.51.100.42')
                ->assertUnauthorized();
        }

        $this->dav('PROPFIND', '/dav/', 'ada', $token, '198.51.100.42', $this->propfind())
            ->assertStatus(207);
    }

    public function test_ordinary_dav_errors_are_stored_as_http_errors_not_login_failures(): void
    {
        $ada = $this->makeUser('ada', 'ada@example.com');
        $this->makeUser('bob', 'bob@example.com');
        $token = app(AppPasswordService::class)->issue($ada, 'phone')['token'];

        $response = $this->dav('PROPFIND', '/dav/calendars/bob', 'ada', $token, '198.51.100.30', $this->propfind());
        $response->assertNotFound();

        $this->assertSame(0, DiagnosticEvent::query()->where('kind', DiagnosticEvent::KIND_AUTH_FAILED)->count());
        $row = DiagnosticEvent::query()->where('kind', DiagnosticEvent::KIND_HTTP_ERROR)->first();
        $this->assertInstanceOf(DiagnosticEvent::class, $row);
        $this->assertSame(404, $row->status);
        $this->assertSame('ada', $row->username);
        $this->assertSame('/dav/calendars/bob', $row->path);
    }

    public function test_kind_accepts_http_error(): void
    {
        DiagnosticEvent::query()->create([
            'occurred_at' => now(),
            'source' => DiagnosticEvent::SOURCE_DAV,
            'kind' => DiagnosticEvent::KIND_HTTP_ERROR,
            'ip' => '203.0.113.9',
            'username' => 'ada',
            'public_id' => null,
            'method' => 'GET',
            'path' => '/dav/calendars/ada/missing',
            'status' => 404,
            'reason' => '',
            'repeat_count' => 1,
            'bucket' => intdiv(now()->getTimestamp(), 60),
        ]);

        $this->assertSame(1, DiagnosticEvent::query()->where('kind', DiagnosticEvent::KIND_HTTP_ERROR)->count());
    }

    public function test_expired_rows_are_pruned_at_most_once_per_interval(): void
    {
        config([
            'nexusdav.diagnostics.retention_days' => 30,
            'nexusdav.diagnostics.prune_interval_seconds' => 3600,
        ]);

        $this->plant('old-a', now()->subDays(31));

        app(DiagnosticEventWriter::class)->recordAuthFailure(
            DiagnosticEvent::SOURCE_DAV,
            '203.0.113.40',
            'ada',
            null,
            'PROPFIND',
            '/dav/',
            401,
            DavAuthBackend::BAD_SECRET,
        );

        $this->assertSame(0, DiagnosticEvent::query()->where('username', 'old-a')->count());
        $this->assertSame(1, DiagnosticEvent::query()->where('username', 'ada')->count());

        $this->plant('old-b', now()->subDays(31));

        app(DiagnosticEventWriter::class)->recordAuthFailure(
            DiagnosticEvent::SOURCE_DAV,
            '203.0.113.40',
            'ada',
            null,
            'PROPFIND',
            '/dav/',
            401,
            DavAuthBackend::BAD_SECRET,
        );

        $this->assertSame(1, DiagnosticEvent::query()->where('username', 'old-b')->count());

        Cache::flush();

        app(DiagnosticEventWriter::class)->recordAuthFailure(
            DiagnosticEvent::SOURCE_DAV,
            '203.0.113.40',
            'ada',
            null,
            'PROPFIND',
            '/dav/',
            401,
            DavAuthBackend::BAD_SECRET,
        );

        $this->assertSame(0, DiagnosticEvent::query()->where('username', 'old-b')->count());
        $this->assertSame(1, DiagnosticEvent::query()->where('username', 'ada')->count());
        $this->assertSame(3, DiagnosticEvent::query()->where('username', 'ada')->value('repeat_count'));
    }

    private function failAdminLogin(string $email, int $times): void
    {
        $component = Livewire::test(Login::class);

        foreach (range(1, $times) as $attempt) {
            $component
                ->fillForm([
                    'email' => $email,
                    'password' => 'wrong-password-'.$attempt,
                ])
                ->call('authenticate')
                ->assertHasFormErrors(['email']);
        }
    }

    private function assertAdminThrottled(string $email, string $password): void
    {
        Livewire::test(Login::class)
            ->fillForm([
                'email' => $email,
                'password' => $password,
            ])
            ->call('authenticate')
            ->assertNotified('Too many login attempts');

        $this->assertGuest();
    }

    private function useClientIp(string $ip): void
    {
        $this->clientIp = $ip;

        if ($this->clientIpBound) {
            return;
        }

        $this->clientIpBound = true;

        $this->app->rebinding('request', function ($app, $request): void {
            if ($request instanceof Request) {
                $request->server->set('REMOTE_ADDR', $this->clientIp);
            }
        });
    }

    private function makeUser(string $username, string $email): User
    {
        return app(UserService::class)->create([
            'username' => $username,
            'display_name' => $username,
            'email' => $email,
            'is_admin' => false,
            'password' => 'correct-horse-battery',
        ]);
    }

    /**
     * @param  array<string, string>  $server
     */
    private function dav(
        string $method,
        string $uri,
        ?string $username,
        ?string $password,
        string $ip,
        ?string $body = null,
        array $server = [],
    ): TestResponse {
        $server = [
            'REMOTE_ADDR' => $ip,
            'HTTP_DEPTH' => '0',
            ...$server,
        ];

        if ($body !== null) {
            $server['CONTENT_TYPE'] = 'application/xml';
        }

        if ($username !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Basic '.base64_encode($username.':'.(string) $password);
        }

        return $this->call($method, $uri, [], [], [], $server, $body);
    }

    private function assertPlainUnauthorized(TestResponse $response, string ...$secrets): void
    {
        $response->assertUnauthorized();
        $payload = $response->getContent()."\n".json_encode($response->headers->all());

        foreach ([
            'unknown user',
            'bad secret',
            'revoked',
            'expired',
            'user disabled',
            'main password used',
            'bad password',
            ...$secrets,
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $payload);
        }
    }

    /**
     * @return object{lines: list<array{level: string, message: string, context: array<string, mixed>}>}
     */
    private function captureLogs(): object
    {
        $logged = new \stdClass;
        $logged->lines = [];

        Log::listen(function (MessageLogged $event) use ($logged): void {
            if ($event->message !== 'Authentication failed') {
                return;
            }

            $logged->lines[] = [
                'level' => $event->level,
                'message' => $event->message,
                'context' => $event->context,
            ];
        });

        return $logged;
    }

    /**
     * @param  callable(): void  $callback
     * @return list<array{query: string, bindings: array<int, mixed>}>
     */
    private function logged(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $callback();
        } finally {
            DB::disableQueryLog();
        }

        return DB::getQueryLog();
    }

    /**
     * @param  list<array{query: string, bindings: array<int, mixed>}>  $queries
     */
    private function assertNoAppPasswordQuery(array $queries, string $secret): void
    {
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('app_passwords', strtolower($query['query']));
            $this->assertStringNotContainsString($secret, strtolower($query['query']));

            foreach ($query['bindings'] as $binding) {
                if (is_string($binding)) {
                    $this->assertStringNotContainsString($secret, $binding);
                }
            }
        }
    }

    private function propfind(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8" ?>
<D:propfind xmlns:D="DAV:">
  <D:prop>
    <D:current-user-principal/>
  </D:prop>
</D:propfind>
XML;
    }

    private function plant(string $username, Carbon $occurredAt): void
    {
        DiagnosticEvent::query()->create([
            'occurred_at' => $occurredAt,
            'source' => DiagnosticEvent::SOURCE_DAV,
            'kind' => DiagnosticEvent::KIND_AUTH_FAILED,
            'ip' => '203.0.113.40',
            'username' => $username,
            'public_id' => null,
            'method' => 'PROPFIND',
            'path' => '/dav/old',
            'status' => 401,
            'reason' => DavAuthBackend::BAD_SECRET,
            'repeat_count' => 1,
            'bucket' => 1,
        ]);
    }
}
