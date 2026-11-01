<?php

namespace Tests\Feature;

use App\Filament\Auth\Login;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\DiagnosticEvent;
use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\AuthThrottle;
use App\Services\UserService;
use App\Support\EnvironmentChecker;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Livewire\Livewire;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * PR 5a: findings R11 to R17 from the code review.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_many_usernames_from_one_address_make_one_summary_row(): void
    {
        config(['nexusdav.diagnostics.max_rows_per_address' => 3]);
        $lines = $this->captureLogs();

        foreach (range(1, 8) as $attempt) {
            $this->dav('PROPFIND', '/dav/', 'nobody'.$attempt, 'x.y', '198.51.100.20')->assertUnauthorized();
        }

        $this->assertSame(4, DiagnosticEvent::query()->count());
        $summary = DiagnosticEvent::query()->where('reason', DiagnosticEvent::REASON_MANY_USERNAMES)->first();
        $this->assertInstanceOf(DiagnosticEvent::class, $summary);
        $this->assertSame('*', $summary->username);
        $this->assertSame('198.51.100.20', $summary->ip);
        $this->assertSame(5, $summary->repeat_count);
        $this->assertCount(4, $lines->lines);

        // Another address still gets its own rows.
        $this->dav('PROPFIND', '/dav/', 'someone', 'x.y', '198.51.100.21')->assertUnauthorized();
        $this->assertSame(1, DiagnosticEvent::query()->where('ip', '198.51.100.21')->where('username', 'someone')->count());
    }

    public function test_per_address_limit_blocks_a_spraying_client_but_not_others(): void
    {
        config([
            'nexusdav.trusted_proxies' => '10.0.0.1',
            'nexusdav.rate_limit.max_attempts_per_address' => 4,
            'nexusdav.rate_limit.max_attempts' => 100,
        ]);
        $ada = $this->makeUser('ada', 'ada@example.com');
        $token = app(AppPasswordService::class)->issue($ada, 'phone')['token'];

        foreach (range(1, 4) as $attempt) {
            $this->dav('PROPFIND', '/dav/', 'guess'.$attempt, 'x.y', '198.51.100.30')->assertUnauthorized();
        }

        $this->dav('PROPFIND', '/dav/', 'ada', $token, '198.51.100.30')->assertUnauthorized();
        $this->dav('PROPFIND', '/dav/', 'ada', $token, '198.51.100.31')->assertStatus(207);
        $this->assertSame(4, (int) DiagnosticEvent::query()->sum('repeat_count'));

        // A client behind the listed proxy is counted by its forwarded address.
        foreach (range(1, 4) as $attempt) {
            $this->dav('PROPFIND', '/dav/', 'guess'.$attempt, 'x.y', '10.0.0.1', [
                'HTTP_X_FORWARDED_FOR' => '203.0.113.40',
            ])->assertUnauthorized();
        }

        $this->dav('PROPFIND', '/dav/', 'ada', $token, '10.0.0.1', ['HTTP_X_FORWARDED_FOR' => '203.0.113.40'])->assertUnauthorized();
        $this->dav('PROPFIND', '/dav/', 'ada', $token, '10.0.0.1', ['HTTP_X_FORWARDED_FOR' => '203.0.113.41'])->assertStatus(207);
    }

    public function test_per_address_limit_also_stops_admin_logins_from_that_address(): void
    {
        config([
            'nexusdav.trusted_proxies' => '10.0.0.1',
            'nexusdav.rate_limit.max_attempts_per_address' => 3,
            'nexusdav.rate_limit.max_attempts' => 100,
        ]);
        $admin = User::factory()->create([
            'email' => 'root@example.com',
            'password' => 'secret-password',
            'is_admin' => true,
            'is_active' => true,
        ]);
        $this->useClientIp('198.51.100.50');

        foreach (range(1, 3) as $attempt) {
            $this->dav('PROPFIND', '/dav/', 'spray'.$attempt, 'x.y', '198.51.100.50')->assertUnauthorized();
        }

        Livewire::test(Login::class)
            ->fillForm(['email' => $admin->email, 'password' => 'secret-password'])
            ->call('authenticate')
            ->assertNotified('Too many login attempts');
        $this->assertGuest();

        $this->useClientIp('198.51.100.51');
        Livewire::test(Login::class)
            ->fillForm(['email' => $admin->email, 'password' => 'secret-password'])
            ->call('authenticate');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_per_address_limit_is_off_when_every_peer_is_trusted(): void
    {
        config([
            'nexusdav.trusted_proxies' => '*',
            'nexusdav.rate_limit.max_attempts_per_address' => 2,
            'nexusdav.rate_limit.max_attempts' => 100,
        ]);
        $ada = $this->makeUser('ada', 'ada@example.com');
        $token = app(AppPasswordService::class)->issue($ada, 'phone')['token'];

        foreach (range(1, 5) as $attempt) {
            $this->dav('PROPFIND', '/dav/', 'guess'.$attempt, 'x.y', '10.9.9.9')->assertUnauthorized();
        }

        $this->assertFalse(app(AuthThrottle::class)->addressIsReal());
        $this->dav('PROPFIND', '/dav/', 'ada', $token, '10.9.9.9')->assertStatus(207);
    }

    public function test_trusted_proxies_default_to_the_private_ranges(): void
    {
        $saved = [getenv('TRUSTED_PROXIES'), $_ENV['TRUSTED_PROXIES'] ?? null, $_SERVER['TRUSTED_PROXIES'] ?? null];

        try {
            putenv('TRUSTED_PROXIES=');
            $_ENV['TRUSTED_PROXIES'] = '';
            $_SERVER['TRUSTED_PROXIES'] = '';
            $config = require config_path('nexusdav.php');
        } finally {
            putenv('TRUSTED_PROXIES='.(string) $saved[0]);
            $_ENV['TRUSTED_PROXIES'] = $saved[1];
            $_SERVER['TRUSTED_PROXIES'] = $saved[2];
        }

        $this->assertSame('10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.1,::1,fc00::/7', $config['trusted_proxies']);
        config(['nexusdav.trusted_proxies' => $config['trusted_proxies']]);

        $this->assertTrue(app(AuthThrottle::class)->addressIsReal());
        $this->dav('PROPFIND', '/dav/', 'nobody', 'x.y', '172.18.0.5', ['HTTP_X_FORWARDED_FOR' => '203.0.113.9'])->assertUnauthorized();
        $this->assertSame('203.0.113.9', DiagnosticEvent::query()->value('ip'));
    }

    public function test_prepare_warns_when_every_peer_is_trusted(): void
    {
        $this->makeUser('root', 'root@example.com', true);
        $this->setEnv('APP_URL', 'http://localhost:8080');

        config(['nexusdav.trusted_proxies' => '*']);
        $this->artisan('nexusdav:prepare')->expectsOutputToContain('TRUSTED_PROXIES is "*"')->assertSuccessful();

        config(['nexusdav.trusted_proxies' => '172.18.0.2']);
        $this->artisan('nexusdav:prepare')->doesntExpectOutputToContain('TRUSTED_PROXIES')->assertSuccessful();
    }

    public function test_overlong_client_values_get_a_client_error_not_a_server_error(): void
    {
        $ada = $this->makeUser('ada', 'ada@example.com');
        $token = app(AppPasswordService::class)->issue($ada, 'phone')['token'];
        $long = str_repeat('x', 201);

        $this->assertSame(400, $this->dav('MKCALENDAR', '/dav/calendars/ada/'.$long, 'ada', $token)->getStatusCode());
        $this->assertSame(400, $this->dav('MKCALENDAR', '/dav/calendars/ada/named', 'ada', $token, '127.0.0.1', [], $this->mkcalendar(str_repeat('n', 256)))->getStatusCode());
        $this->assertSame(0, DB::table('calendarinstances')->where('uri', 'named')->count());

        $this->assertRefused($this->dav('PROPPATCH', '/dav/calendars/ada/default/', 'ada', $token, '127.0.0.1', [], $this->proppatch('<a:calendar-color>#11223344556</a:calendar-color>')));
        $this->assertRefused($this->dav('PROPPATCH', '/dav/calendars/ada/default/', 'ada', $token, '127.0.0.1', [], $this->proppatch('<a:calendar-order>99999999999</a:calendar-order>')));
        $this->assertRefused($this->dav('PROPPATCH', '/dav/calendars/ada/default/', 'ada', $token, '127.0.0.1', [], $this->proppatch('<d:displayname>'.str_repeat('n', 256).'</d:displayname>')));
        $this->assertSame('#1A73E8', DB::table('calendarinstances')->where('principaluri', 'principals/ada')->value('calendarcolor'));

        $deadName = str_repeat('p', 250);
        $this->assertRefused($this->dav('PROPPATCH', '/dav/calendars/ada/default/', 'ada', $token, '127.0.0.1', [], $this->proppatch('<x:'.$deadName.' xmlns:x="urn:example">v</x:'.$deadName.'>')));

        $this->assertSame(400, $this->dav('PUT', '/dav/calendars/ada/default/'.$long.'.ics', 'ada', $token, '127.0.0.1', ['CONTENT_TYPE' => 'text/calendar'], $this->event('ok@nexusdav.test'))->getStatusCode());
        $this->assertSame(400, $this->dav('PUT', '/dav/calendars/ada/default/long-uid.ics', 'ada', $token, '127.0.0.1', ['CONTENT_TYPE' => 'text/calendar'], $this->event(str_repeat('u', 201)))->getStatusCode());
        $this->assertSame(0, DB::table('calendarobjects')->count());

        $this->assertSame(400, $this->dav('MKCOL', '/dav/addressbooks/ada/'.$long, 'ada', $token, '127.0.0.1', [], $this->mkcolAddressBook())->getStatusCode());
        $this->assertRefused($this->dav('PROPPATCH', '/dav/addressbooks/ada/default/', 'ada', $token, '127.0.0.1', [], $this->proppatch('<d:displayname>'.str_repeat('n', 256).'</d:displayname>')));
        $this->assertSame(400, $this->dav('PUT', '/dav/addressbooks/ada/default/'.$long.'.vcf', 'ada', $token, '127.0.0.1', ['CONTENT_TYPE' => 'text/vcard'], $this->card())->getStatusCode());
        $this->assertSame(0, DB::table('cards')->count());

        // Values at the limit are kept.
        $this->dav('PROPPATCH', '/dav/calendars/ada/default/', 'ada', $token, '127.0.0.1', [], $this->proppatch('<a:calendar-color>#11223344</a:calendar-color>'))->assertStatus(207);
        $this->assertSame('#11223344', DB::table('calendarinstances')->where('principaluri', 'principals/ada')->value('calendarcolor'));

        // A long LOCK owner was a 500 on PostgreSQL (varchar(100)).
        $lock = $this->dav('LOCK', '/dav/calendars/ada/default/', 'ada', $token, '127.0.0.1', ['HTTP_TIMEOUT' => 'Second-99999999999'], $this->lockInfo(str_repeat('o', 300)));
        $this->assertSame(200, $lock->getStatusCode(), $this->body($lock));
    }

    public function test_passwords_need_twelve_characters_and_a_confirmation(): void
    {
        try {
            $this->makeUser('ada', 'ada@example.com', true, 'short-pass1');
            $this->fail('An eleven-character password was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('at least 12 characters', $exception->getMessage());
        }

        $this->actingAs($this->makeUser('root', 'root@example.com', true));

        Livewire::test(CreateUser::class)
            ->fillForm([
                'username' => 'bob',
                'display_name' => 'Bob',
                'email' => 'bob@example.com',
                'password' => 'short-pass1',
                'password_confirmation' => 'short-pass1',
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'username' => 'bob',
                'display_name' => 'Bob',
                'email' => 'bob@example.com',
                'password' => 'twelve-chars-ok',
                'password_confirmation' => 'twelve-chars-no',
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'username' => 'bob',
                'display_name' => 'Bob',
                'email' => 'bob@example.com',
                'password' => 'twelve-chars-ok',
                'password_confirmation' => 'twelve-chars-ok',
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertNotNull(User::query()->where('username', 'bob')->first());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ADMIN_PASSWORD must be at least 12 characters');
        app(EnvironmentChecker::class)->assertAdminCredentials([
            'ADMIN_EMAIL' => 'admin@example.com',
            'ADMIN_PASSWORD' => 'short-pass1',
        ], false);
    }

    public function test_emails_are_stored_lower_case_and_admin_login_ignores_case(): void
    {
        $admin = $this->makeUser('root', 'Root.Admin@Example.COM', true);

        $this->assertSame('root.admin@example.com', $admin->fresh()?->email);
        $this->assertSame('root.admin@example.com', $admin->principal?->fresh()?->email);
        $this->assertSame('mixed@example.com', User::factory()->create(['email' => 'MiXeD@Example.com'])->email);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'ROOT.ADMIN@example.com', 'password' => 'correct-horse-battery'])
            ->call('authenticate');
        $this->assertAuthenticatedAs($admin);

        $this->expectException(QueryException::class);
        DB::table('users')->insert([
            'username' => 'clash',
            'display_name' => 'Clash',
            'email' => 'ROOT.admin@example.com',
            'password' => 'x',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_email_migration_lowercases_and_stops_on_a_collision(): void
    {
        $migration = require database_path('migrations/2026_11_01_000000_lowercase_user_emails.php');
        $migration->down();

        $user = $this->makeUser('ada', 'ada@example.com');
        DB::table('users')->where('id', $user->getKey())->update(['email' => 'Ada@Example.com']);
        DB::table('principals')->where('user_id', $user->getKey())->update(['email' => 'Ada@Example.com']);

        $migration->up();
        $this->assertSame('ada@example.com', DB::table('users')->where('id', $user->getKey())->value('email'));
        $this->assertSame('ada@example.com', DB::table('principals')->where('user_id', $user->getKey())->value('email'));

        $migration->down();
        $other = $this->makeUser('ada2', 'other@example.com');
        DB::table('users')->where('id', $other->getKey())->update(['email' => 'ADA@example.com']);

        try {
            $migration->up();
            $this->fail('The migration accepted two emails that differ only in case.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ada@example.com', $exception->getMessage());
        }
    }

    public function test_panel_responses_carry_security_headers_and_dav_responses_do_not(): void
    {
        $login = $this->get('/admin/login');
        $login->assertOk();
        $login->assertHeader('X-Frame-Options', 'DENY');
        $login->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
        $login->assertHeader('Referrer-Policy', 'same-origin');
        $login->assertHeader('X-Content-Type-Options', 'nosniff');

        $ada = $this->makeUser('ada', 'ada@example.com');
        $token = app(AppPasswordService::class)->issue($ada, 'phone')['token'];
        $dav = $this->dav('PROPFIND', '/dav/', 'ada', $token, '127.0.0.1');
        $dav->assertStatus(207);
        $this->assertFalse($dav->headers->has('X-Frame-Options'));
        $this->assertFalse($this->get('/health')->headers->has('X-Frame-Options'));
    }

    private function makeUser(string $username, string $email, bool $admin = false, string $password = 'correct-horse-battery'): User
    {
        return app(UserService::class)->create([
            'username' => $username,
            'display_name' => ucfirst($username),
            'email' => $email,
            'is_admin' => $admin,
            'password' => $password,
        ]);
    }

    /**
     * @param  array<string, string>  $server
     */
    private function dav(string $method, string $uri, string $username, string $password, string $ip = '127.0.0.1', array $server = [], ?string $body = null): TestResponse
    {
        $server = [
            'REMOTE_ADDR' => $ip,
            'HTTP_DEPTH' => '0',
            'CONTENT_TYPE' => 'application/xml',
            'HTTP_AUTHORIZATION' => 'Basic '.base64_encode($username.':'.$password),
            ...$server,
        ];

        return $this->call($method, $uri, [], [], [], $server, $body);
    }

    private function body(TestResponse $response): string
    {
        if ($response->baseResponse instanceof StreamedResponse) {
            return $response->streamedContent();
        }

        return (string) $response->getContent();
    }

    private function assertRefused(TestResponse $response): void
    {
        $body = $this->body($response);

        $this->assertSame(207, $response->getStatusCode(), $body);
        $this->assertStringContainsString('403', $body);
    }

    private function useClientIp(string $ip): void
    {
        $this->app->rebinding('request', function ($app, $request) use ($ip): void {
            if ($request instanceof Request) {
                $request->server->set('REMOTE_ADDR', $ip);
            }
        });
        request()->server->set('REMOTE_ADDR', $ip);
    }

    private function setEnv(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    /**
     * @return object{lines: list<string>}
     */
    private function captureLogs(): object
    {
        $logged = new \stdClass;
        $logged->lines = [];

        Log::listen(function (MessageLogged $event) use ($logged): void {
            if ($event->message === 'Authentication failed') {
                $logged->lines[] = (string) ($event->context['reason'] ?? '');
            }
        });

        return $logged;
    }

    private function mkcalendar(string $displayName): string
    {
        return '<?xml version="1.0"?><c:mkcalendar xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
            .'<d:set><d:prop><d:displayname>'.$displayName.'</d:displayname></d:prop></d:set></c:mkcalendar>';
    }

    private function mkcolAddressBook(): string
    {
        return '<?xml version="1.0"?><d:mkcol xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">'
            .'<d:set><d:prop><d:resourcetype><d:collection/><card:addressbook/></d:resourcetype></d:prop></d:set></d:mkcol>';
    }

    private function proppatch(string $property): string
    {
        return '<?xml version="1.0"?><d:propertyupdate xmlns:d="DAV:" xmlns:a="http://apple.com/ns/ical/">'
            .'<d:set><d:prop>'.$property.'</d:prop></d:set></d:propertyupdate>';
    }

    private function lockInfo(string $owner): string
    {
        return '<?xml version="1.0"?><d:lockinfo xmlns:d="DAV:"><d:lockscope><d:exclusive/></d:lockscope>'
            .'<d:locktype><d:write/></d:locktype><d:owner>'.$owner.'</d:owner></d:lockinfo>';
    }

    private function event(string $uid): string
    {
        return implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//NexusDAV//EN',
            'BEGIN:VEVENT', 'UID:'.$uid, 'DTSTAMP:20261101T120000Z',
            'DTSTART:20261102T130000Z', 'SUMMARY:Limit', 'END:VEVENT', 'END:VCALENDAR', '',
        ]);
    }

    private function card(): string
    {
        return implode("\r\n", ['BEGIN:VCARD', 'VERSION:3.0', 'UID:card-1', 'FN:Card', 'N:Card;;;;', 'END:VCARD', '']);
    }
}
