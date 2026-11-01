<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\Dav\AddressBookBackend;
use App\Services\Dav\CalendarBackend;
use App\Services\Dav\DavAuthBackend;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class DavHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_password_authenticates_and_email_login_uses_the_username(): void
    {
        $user = $this->makeUser('ada', 'Ada Lovelace', 'Countess@Example.com');
        $token = $this->token($user);

        foreach (['ada', 'Ada', 'countess@example.com'] as $login) {
            $response = $this->dav('PROPFIND', '/dav/', $login, $token, [
                'Depth' => '0',
                'Content-Type' => 'application/xml',
            ], $this->currentUserPrincipal());

            $response->assertStatus(207);
            $this->assertNull(app(DavAuthBackend::class)->failureReason());
            $this->assertSame('ada', app(DavAuthBackend::class)->username());
            $this->assertMatchesRegularExpression('#/dav/principals/ada/#', $this->body($response));
            $this->assertStringNotContainsString('/dav/principals/countess@example.com', $this->body($response));
            $this->assertStringNotContainsString('text/html', (string) $response->headers->get('Content-Type'));
            $this->assertFalse($response->headers->has('Set-Cookie'));
        }
    }

    public function test_auth_failures_are_distinct_and_the_client_sees_only_401(): void
    {
        $ada = $this->makeUser('ada', 'Ada Lovelace', 'ada@example.com');
        $bob = $this->makeUser('bob', 'Bob Baker', 'bob@example.com');
        $passwords = app(AppPasswordService::class);
        $issued = $passwords->issue($ada, 'phone');
        $token = $issued['token'];
        [$publicId, $secret] = explode('.', $token, 2);
        $revoked = $passwords->issue($ada, 'revoked');
        $passwords->revoke($revoked['app_password']);
        $expired = $passwords->issue($ada, 'expired', now()->subMinute());
        $bobToken = $this->token($bob);

        $unknown = $this->logged(function (): void {
            $response = $this->dav('PROPFIND', '/dav/', 'nobody', 'missing-public.missing-secret', [
                'Depth' => '0',
                'Content-Type' => 'application/xml',
            ], $this->currentUserPrincipal());
            $this->assertSame(DavAuthBackend::UNKNOWN_USER, app(DavAuthBackend::class)->failureReason());
            $this->assertSame('missing-public', app(DavAuthBackend::class)->publicId());
            $this->assertPlainUnauthorized($response, 'missing-public', 'missing-secret');
        });
        // An unknown username still pays for the token lookup, so timing does not reveal it.
        $this->assertOnePublicIdLookup($unknown, 'missing-secret');

        $wrong = $this->dav('PROPFIND', '/dav/', 'ada', $publicId.'.not-the-secret', [
            'Depth' => '0',
        ], $this->currentUserPrincipal());
        $this->assertSame(DavAuthBackend::BAD_SECRET, app(DavAuthBackend::class)->failureReason());
        $this->assertSame($publicId, app(DavAuthBackend::class)->publicId());
        $this->assertPlainUnauthorized($wrong, $publicId, 'not-the-secret', $secret);

        $revokedResponse = $this->dav('PROPFIND', '/dav/', 'ada', $revoked['token']);
        $this->assertSame(DavAuthBackend::REVOKED, app(DavAuthBackend::class)->failureReason());
        $this->assertPlainUnauthorized($revokedResponse, $revoked['token']);

        $expiredResponse = $this->dav('PROPFIND', '/dav/', 'ada', $expired['token']);
        $this->assertSame(DavAuthBackend::EXPIRED, app(DavAuthBackend::class)->failureReason());
        $this->assertPlainUnauthorized($expiredResponse, $expired['token']);

        $main = $this->logged(function (): void {
            $response = $this->dav('PROPFIND', '/dav/', 'ada', 'correct-horse-battery');
            $this->assertSame(DavAuthBackend::MAIN_PASSWORD, app(DavAuthBackend::class)->failureReason());
            $this->assertNull(app(DavAuthBackend::class)->publicId());
            $this->assertPlainUnauthorized($response, 'correct-horse-battery');
        });
        $this->assertNoTokenLookup($main, 'correct-horse-battery');

        $foreign = $this->dav('PROPFIND', '/dav/', 'ada', $bobToken);
        $this->assertSame(DavAuthBackend::BAD_SECRET, app(DavAuthBackend::class)->failureReason());
        $this->assertPlainUnauthorized($foreign, $bobToken);
        $this->dav('PROPFIND', '/dav/', 'bob', $bobToken, ['Depth' => '0'], $this->currentUserPrincipal())->assertStatus(207);

        $missing = $this->dav('PROPFIND', '/dav/');
        $this->assertNull(app(DavAuthBackend::class)->failureReason());
        $this->assertNull(app(DavAuthBackend::class)->publicId());
        $this->assertPlainUnauthorized($missing);

        $post = $this->call('POST', '/dav/principals/', [], [], [], [
            'CONTENT_TYPE' => 'application/xml',
        ], '<d:propfind xmlns:d="DAV:"/>');
        $this->assertSame(401, $post->getStatusCode());
        $this->assertFalse($post->headers->has('Set-Cookie'));
        $this->assertStringNotContainsString('laravel_session', (string) $post->headers->get('Set-Cookie'));
    }

    public function test_disabled_user_is_refused_until_reenabled(): void
    {
        $user = $this->makeUser('ada', 'Ada Lovelace', 'ada@example.com');
        $token = $this->token($user);

        app(UserService::class)->update($user, ['is_active' => false]);

        $disabled = $this->dav('PROPFIND', '/dav/', 'ada', $token, ['Depth' => '0'], $this->currentUserPrincipal());
        $this->assertSame(DavAuthBackend::USER_DISABLED, app(DavAuthBackend::class)->failureReason());
        $this->assertPlainUnauthorized($disabled, $token);

        app(UserService::class)->update($user, ['is_active' => true]);

        $this->dav('PROPFIND', '/dav/', 'ada', $token, ['Depth' => '0'], $this->currentUserPrincipal())->assertStatus(207);
        $this->assertNull(app(DavAuthBackend::class)->failureReason());
    }

    public function test_basic_auth_looks_up_one_token(): void
    {
        $user = $this->makeUser('ada', 'Ada Lovelace', 'ada@example.com');
        $passwords = app(AppPasswordService::class);
        $issued = $passwords->issue($user, 'phone');
        $passwords->issue($user, 'tablet');
        [$publicId, $secret] = explode('.', $issued['token'], 2);

        $queries = $this->logged(function () use ($issued): void {
            $this->dav('PROPFIND', '/dav/', 'ada', $issued['token'], [
                'Depth' => '0',
                'Content-Type' => 'application/xml',
            ], $this->currentUserPrincipal())->assertStatus(207);
        });
        $this->assertOnePublicIdLookup($queries, $secret);
        $this->assertNotNull($issued['app_password']->refresh()->last_used_at);

        $emailQueries = $this->logged(function () use ($issued): void {
            $this->dav('PROPFIND', '/dav/', 'ada@example.com', $issued['token'], [
                'Depth' => '0',
            ], $this->currentUserPrincipal())->assertStatus(207);
        });
        $this->assertOnePublicIdLookup($emailQueries, $secret);

        $wrongQueries = $this->logged(function () use ($publicId): void {
            $response = $this->dav('PROPFIND', '/dav/', 'ada', $publicId.'.not-the-secret');
            $this->assertSame(DavAuthBackend::BAD_SECRET, app(DavAuthBackend::class)->failureReason());
            $response->assertUnauthorized();
        });
        $this->assertOnePublicIdLookup($wrongQueries, 'not-the-secret');

        $mainQueries = $this->logged(function (): void {
            $response = $this->dav('PROPFIND', '/dav/', 'ada', 'correct-horse-battery');
            $this->assertSame(DavAuthBackend::MAIN_PASSWORD, app(DavAuthBackend::class)->failureReason());
            $response->assertUnauthorized();
        });
        $this->assertNoTokenLookup($mainQueries, 'correct-horse-battery');
    }

    public function test_other_users_collections_are_unreachable(): void
    {
        $ada = $this->makeUser('ada', 'Ada Lovelace', 'ada@example.com');
        $bob = $this->makeUser('bob', 'Bob Baker', 'bob@example.com');
        $adaToken = $this->token($ada);
        $secret = 'Bob Secret Meeting';

        $calendars = new CalendarBackend(DB::connection()->getPdo());
        $adaCalendar = $calendars->createCalendar('principals/ada', 'personal', [
            '{DAV:}displayname' => 'Ada',
        ]);
        $calendars->createCalendarObject($adaCalendar, 'planning.ics', $this->event('ada-event@nexusdav.test', 'Ada Planning'));
        $bobCalendar = $calendars->createCalendar('principals/bob', 'personal', [
            '{DAV:}displayname' => 'Bob',
        ]);
        $calendars->createCalendarObject($bobCalendar, 'planning.ics', $this->event('bob-event@nexusdav.test', $secret));

        $books = new AddressBookBackend(DB::connection()->getPdo());
        $bookId = $books->createAddressBook('principals/bob', 'contacts', [
            '{DAV:}displayname' => 'Bob',
        ]);
        $books->createCard($bookId, 'bob.vcf', $this->card($secret));

        $own = $this->dav('GET', '/dav/calendars/ada/personal/planning.ics', 'ada', $adaToken);
        $own->assertOk();
        $this->assertStringContainsString('Ada Planning', $this->body($own));

        $this->assertDenied($this->dav('PROPFIND', '/dav/calendars/bob', 'ada', $adaToken, ['Depth' => '1'], $this->propfind()), $secret);
        $this->assertDenied($this->dav('PROPFIND', '/dav/calendars/bob/personal', 'ada', $adaToken, ['Depth' => '1'], $this->propfind()), $secret);
        $this->assertDenied($this->dav('PROPFIND', '/dav/calendars/bob/personal/planning.ics', 'ada', $adaToken, ['Depth' => '0'], $this->propfind()), $secret);
        $this->assertDenied($this->dav('GET', '/dav/calendars/bob/personal/planning.ics', 'ada', $adaToken), $secret);
        $this->assertDenied($this->dav('PUT', '/dav/calendars/bob/personal/evil.ics', 'ada', $adaToken, [
            'Content-Type' => 'text/calendar',
        ], $this->event('evil@nexusdav.test', $secret)), $secret);
        $this->assertDenied($this->dav('DELETE', '/dav/calendars/bob/personal/planning.ics', 'ada', $adaToken), $secret);
        $this->assertDenied($this->dav('REPORT', '/dav/calendars/bob/personal', 'ada', $adaToken, [
            'Depth' => '1',
            'Content-Type' => 'application/xml',
        ], $this->calendarQuery()), $secret);
        $this->assertDenied($this->dav('MKCALENDAR', '/dav/calendars/bob/hacked', 'ada', $adaToken), $secret);
        $this->assertDenied($this->dav('COPY', '/dav/calendars/ada/personal/planning.ics', 'ada', $adaToken, [
            'Destination' => 'https://dav.example.com/dav/calendars/bob/personal/stolen.ics',
            'Overwrite' => 'T',
        ], null, $this->forwarded()), $secret);
        $this->assertDenied($this->dav('MOVE', '/dav/calendars/ada/personal/planning.ics', 'ada', $adaToken, [
            'Destination' => 'https://dav.example.com/dav/calendars/bob/personal/moved.ics',
            'Overwrite' => 'T',
        ], null, $this->forwarded()), $secret);
        $this->assertDenied($this->dav('PROPFIND', '/dav/addressbooks/bob/contacts', 'ada', $adaToken, ['Depth' => '1'], $this->propfind()), $secret);

        $stillThere = $this->dav('GET', '/dav/calendars/ada/personal/planning.ics', 'ada', $adaToken);
        $stillThere->assertOk();
        $this->assertStringContainsString('Ada Planning', $this->body($stillThere));
        $this->dav('GET', '/dav/calendars/bob/personal/planning.ics', 'bob', $this->token($bob))->assertOk();
    }

    public function test_principal_listing_and_search_hide_other_users(): void
    {
        $ada = $this->makeUser('ada', 'Ada Lovelace', 'ada@example.com');
        $this->makeUser('bob', 'Bob Baker', 'bob@example.com');
        $token = $this->token($ada);

        $listing = $this->dav('PROPFIND', '/dav/principals/', 'ada', $token, [
            'Depth' => '1',
            'Content-Type' => 'application/xml',
        ], $this->propfind());
        $listing->assertStatus(207);
        $listed = $this->body($listing);
        $this->assertStringContainsString('/dav/principals/ada/', $listed);
        $this->assertStringNotContainsString('/dav/principals/bob', $listed);
        $this->assertStringNotContainsString('Bob Baker', $listed);

        $byName = $this->dav('REPORT', '/dav/principals/', 'ada', $token, [
            'Depth' => '0',
            'Content-Type' => 'application/xml',
        ], $this->principalSearch('displayname', 'Bob Baker'));
        $byName->assertStatus(207);
        $this->assertStringNotContainsString('/dav/principals/bob', $this->body($byName));
        $this->assertStringNotContainsString('Bob Baker', $this->body($byName));

        $byEmail = $this->dav('REPORT', '/dav/principals/', 'ada', $token, [
            'Depth' => '0',
            'Content-Type' => 'application/xml',
        ], $this->principalSearch('email', 'bob@example.com'));
        $byEmail->assertStatus(207);
        $this->assertStringNotContainsString('/dav/principals/bob', $this->body($byEmail));
        $this->assertStringNotContainsString('bob@example.com', $this->body($byEmail));

        $self = $this->dav('REPORT', '/dav/principals/', 'ada', $token, [
            'Depth' => '0',
            'Content-Type' => 'application/xml',
        ], $this->principalSearch('displayname', 'Ada Lovelace'));
        $self->assertStatus(207);
        $this->assertStringContainsString('/dav/principals/ada/', $this->body($self));
    }

    public function test_admin_cannot_read_another_users_calendar(): void
    {
        $admin = $this->makeUser('adaadmin', 'Ada Admin', 'admin@example.com', true);
        $bob = $this->makeUser('bob', 'Bob Baker', 'bob@example.com');
        $secret = 'Bob Secret Meeting';

        $calendars = new CalendarBackend(DB::connection()->getPdo());
        $calendar = $calendars->createCalendar('principals/bob', 'personal', [
            '{DAV:}displayname' => 'Bob',
        ]);
        $calendars->createCalendarObject($calendar, 'planning.ics', $this->event('bob-event@nexusdav.test', $secret));

        $token = $this->token($admin);
        $this->assertDenied($this->dav('PROPFIND', '/dav/calendars/bob/personal', 'adaadmin', $token, ['Depth' => '1'], $this->propfind()), $secret);
        $this->assertDenied($this->dav('GET', '/dav/calendars/bob/personal/planning.ics', 'adaadmin', $token), $secret);

        $own = $this->dav('PROPFIND', '/dav/', 'adaadmin', $token, ['Depth' => '0'], $this->currentUserPrincipal());
        $own->assertStatus(207);
        $this->assertStringContainsString('/dav/principals/adaadmin/', $this->body($own));
        $this->assertNotNull($bob->fresh());
    }

    public function test_propfind_keeps_multistatus_headers_and_put_round_trips(): void
    {
        $user = $this->makeUser('ada', 'Ada Lovelace', 'ada@example.com');
        $token = $this->token($user);
        $event = $this->event('ada-event@nexusdav.test', 'Ada Planning');

        $propfind = $this->dav('PROPFIND', '/dav/', 'ada', $token, [
            'Depth' => '0',
            'Content-Type' => 'application/xml',
        ], $this->currentUserPrincipal());
        $propfind->assertStatus(207);
        $this->assertStringContainsString('xml', (string) $propfind->headers->get('Content-Type'));
        $this->assertStringNotContainsString('text/html', (string) $propfind->headers->get('Content-Type'));
        $this->assertNotSame('', (string) $propfind->headers->get('DAV'));
        $this->assertFalse($propfind->headers->has('Set-Cookie'));

        $calendar = $this->dav('MKCALENDAR', '/dav/calendars/ada/personal', 'ada', $token);
        $calendar->assertStatus(201);
        $this->assertNoInventedHtmlType($calendar);

        $put = $this->dav('PUT', '/dav/calendars/ada/personal/planning.ics', 'ada', $token, [
            'Content-Type' => 'text/calendar',
        ], $event);
        $this->assertContains($put->getStatusCode(), [201, 204]);
        $this->assertNoInventedHtmlType($put);
        $this->assertNotSame('', (string) $put->headers->get('ETag'));

        $get = $this->dav('GET', '/dav/calendars/ada/personal/planning.ics', 'ada', $token);
        $get->assertOk();
        $this->assertStringContainsString('Ada Planning', $this->body($get));
        $this->assertStringNotContainsString('text/html', (string) $get->headers->get('Content-Type'));

        $copy = $this->dav('COPY', '/dav/calendars/ada/personal/planning.ics', 'ada', $token, [
            'Destination' => 'https://dav.example.com/dav/calendars/ada/personal/copy.ics',
            'Overwrite' => 'T',
        ], null, $this->forwarded());
        $this->assertContains($copy->getStatusCode(), [201, 204]);
        $this->assertNoInventedHtmlType($copy);

        $copied = $this->dav('GET', '/dav/calendars/ada/personal/copy.ics', 'ada', $token);
        $copied->assertOk();
        $this->assertStringContainsString('Ada Planning', $this->body($copied));
    }

    public function test_lock_of_shared_roots_is_rejected_and_does_not_block_the_owner(): void
    {
        $ada = $this->makeUser('ada', 'Ada Lovelace', 'ada@example.com');
        $bob = $this->makeUser('bob', 'Bob Baker', 'bob@example.com');
        $adaToken = $this->token($ada);
        $bobToken = $this->token($bob);

        $calendars = new CalendarBackend(DB::connection()->getPdo());
        $calendars->createCalendar('principals/bob', 'personal', [
            '{DAV:}displayname' => 'Bob',
        ]);
        $books = new AddressBookBackend(DB::connection()->getPdo());
        $books->createAddressBook('principals/bob', 'contacts', [
            '{DAV:}displayname' => 'Bob',
        ]);

        foreach (['/dav/calendars/', '/dav/addressbooks/'] as $root) {
            $locked = $this->dav('LOCK', $root, 'ada', $adaToken, [
                'Content-Type' => 'application/xml',
            ], $this->lockInfo());
            $this->assertSame(403, $locked->getStatusCode(), $this->body($locked));
            $this->assertStringNotContainsString('opaquelocktoken', strtolower($this->body($locked).(string) $locked->headers->get('Lock-Token')));
        }

        $event = $this->dav('PUT', '/dav/calendars/bob/personal/planning.ics', 'bob', $bobToken, [
            'Content-Type' => 'text/calendar',
        ], $this->event('bob-event@nexusdav.test', 'Bob Planning'));
        $this->assertContains($event->getStatusCode(), [201, 204]);

        $card = $this->dav('PUT', '/dav/addressbooks/bob/contacts/bob.vcf', 'bob', $bobToken, [
            'Content-Type' => 'text/vcard',
        ], $this->card('Bob Baker'));
        $this->assertContains($card->getStatusCode(), [201, 204]);

        $ownCalendar = $this->dav('MKCALENDAR', '/dav/calendars/ada/personal', 'ada', $adaToken);
        $ownCalendar->assertStatus(201);
        $ownEvent = $this->dav('PUT', '/dav/calendars/ada/personal/planning.ics', 'ada', $adaToken, [
            'Content-Type' => 'text/calendar',
        ], $this->event('ada-event@nexusdav.test', 'Ada Planning'));
        $this->assertContains($ownEvent->getStatusCode(), [201, 204]);
    }

    public function test_well_known_redirects_and_forwarded_destination(): void
    {
        config(['app.url' => 'https://dav.example.com']);

        foreach (['GET', 'PROPFIND'] as $method) {
            foreach (['caldav', 'carddav'] as $service) {
                // The target comes from APP_URL. A forged forwarded host is ignored.
                $response = $this->dav($method, '/.well-known/'.$service, null, null, [], null, [
                    ...$this->forwarded(),
                    'HTTP_X_FORWARDED_HOST' => 'evil.example',
                ]);
                $response->assertStatus(301);
                $response->assertHeader('Location', 'https://dav.example.com/dav/');
                $this->assertFalse($response->headers->has('WWW-Authenticate'));
            }
        }

        $this->assertSame(0, DB::table('diagnostic_events')->count());
    }

    public function test_dav_request_log_lines_carry_no_secrets(): void
    {
        $ada = $this->makeUser('ada', 'Ada Lovelace', 'ada@example.com');
        $bob = $this->makeUser('bob', 'Bob Baker', 'bob@example.com');
        $token = $this->token($ada);
        $secret = explode('.', $token, 2)[1];

        $calendars = new CalendarBackend(DB::connection()->getPdo());
        $calendars->createCalendar('principals/bob', 'personal', [
            '{DAV:}displayname' => 'Bob',
        ]);

        $logged = $this->captureRequestLines();

        $this->dav('PROPFIND', '/dav/', 'ada', $token, ['Depth' => '0'], $this->currentUserPrincipal())->assertStatus(207);
        $this->assertSame('ada', $this->requestLine($logged, 207, '/dav')['user']);

        $this->dav('PROPFIND', '/dav/calendars/bob/personal?leak=1', null, null, ['Depth' => '0'], $this->propfind());
        $unauthenticated = $this->requestLine($logged, 401, '/dav/calendars/bob/personal');
        $this->assertSame('PROPFIND', $unauthenticated['method']);
        $this->assertNull($unauthenticated['user']);
        $this->assertStringNotContainsString('leak', $unauthenticated['path']);
        $this->assertStringNotContainsString('?', $unauthenticated['path']);

        // Another user's home is reported as missing, so this is a 404, not a 403.
        $this->dav('PROPFIND', '/dav/calendars/bob/personal', 'ada', $token, ['Depth' => '1'], $this->propfind());
        $hidden = $this->requestLine($logged, 404, '/dav/calendars/bob/personal');
        $this->assertSame('ada', $hidden['user']);
        $this->assertSame(['method', 'path', 'status', 'user', 'ip', 'duration_ms'], array_keys($hidden));

        $encoded = (string) json_encode($logged->lines);
        $this->assertStringNotContainsString($secret, $encoded);
        $this->assertStringNotContainsString($token, $encoded);
        $this->assertStringNotContainsString('bad secret', $encoded);
        $this->assertStringNotContainsString('Bob Secret', $encoded);
    }

    private function makeUser(string $username, string $displayName, string $email, bool $admin = false): User
    {
        return app(UserService::class)->create([
            'username' => $username,
            'display_name' => $displayName,
            'email' => $email,
            'is_admin' => $admin,
            'password' => 'correct-horse-battery',
        ]);
    }

    private function token(User $user): string
    {
        return app(AppPasswordService::class)->issue($user, 'phone')['token'];
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $server
     */
    private function dav(string $method, string $uri, ?string $username = null, ?string $password = null, array $headers = [], ?string $body = null, array $server = []): TestResponse
    {
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        if ($username !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Basic '.base64_encode($username.':'.(string) $password);
        }

        return $this->call($method, $uri, [], [], [], $server, $body);
    }

    /**
     * @return array<string, string>
     */
    private function forwarded(): array
    {
        return [
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'dav.example.com',
            'REMOTE_ADDR' => '10.1.2.3',
        ];
    }

    private function body(TestResponse $response): string
    {
        if ($response->baseResponse instanceof StreamedResponse) {
            return $response->streamedContent();
        }

        return (string) $response->getContent();
    }

    private function assertPlainUnauthorized(TestResponse $response, string ...$secrets): void
    {
        $response->assertUnauthorized();
        $payload = $this->body($response)."\n".json_encode($response->headers->all());

        foreach ([
            'unknown user',
            'bad secret',
            'revoked',
            'expired',
            'user disabled',
            'main password used',
            ...$secrets,
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $payload);
        }

        $this->assertStringContainsString('Basic realm="NexusDAV"', (string) $response->headers->get('WWW-Authenticate'));
        $this->assertFalse($response->headers->has('Set-Cookie'));
    }

    private function assertNoInventedHtmlType(TestResponse $response): void
    {
        $this->assertStringNotContainsString('text/html', strtolower((string) $response->headers->get('Content-Type')));

        if ($response->getStatusCode() === 201) {
            $this->assertFalse($response->headers->has('Content-Type'));
            $this->assertSame('', $this->body($response));
        }
    }

    /**
     * Another user's home is hidden, so a write into it fails like a write
     * under a missing user: 409 when the parent collection does not exist.
     */
    private function assertDenied(TestResponse $response, string $secretText): void
    {
        $body = $this->body($response);

        $this->assertContains($response->getStatusCode(), [403, 404, 409], $body);
        $this->assertStringNotContainsString($secretText, $body);
    }

    /**
     * @param  callable(): void  $callback
     * @return list<array{query: string, bindings: array<int, mixed>, time?: float}>
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
    private function assertOnePublicIdLookup(array $queries, string $secret): void
    {
        $appPassword = array_values(array_filter($queries, function (array $query): bool {
            return str_contains(strtolower($query['query']), 'app_passwords');
        }));
        $lookups = array_values(array_filter($appPassword, function (array $query): bool {
            $sql = strtolower($query['query']);

            return str_starts_with(ltrim($sql), 'select') && str_contains($sql, 'public_id');
        }));

        $this->assertCount(1, $lookups, (string) json_encode($appPassword));

        foreach ($appPassword as $query) {
            $this->assertStringNotContainsString('user_id', strtolower($query['query']));
            $this->assertStringNotContainsString($secret, strtolower($query['query']));

            foreach ($query['bindings'] as $binding) {
                if (is_string($binding)) {
                    $this->assertStringNotContainsString($secret, $binding);
                }
            }
        }
    }

    /**
     * @param  list<array{query: string, bindings: array<int, mixed>}>  $queries
     */
    private function assertNoTokenLookup(array $queries, string $password): void
    {
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('app_passwords', strtolower($query['query']));
            $this->assertStringNotContainsString($password, strtolower($query['query']));

            foreach ($query['bindings'] as $binding) {
                if (is_string($binding)) {
                    $this->assertStringNotContainsString($password, $binding);
                }
            }
        }
    }

    /**
     * The context of each "Request" log line.
     */
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
     * @return array<string, mixed>
     */
    private function requestLine(object $logged, int $status, string $path): array
    {
        foreach ($logged->lines as $line) {
            if ($line['status'] === $status && $line['path'] === $path) {
                return $line;
            }
        }

        $this->fail('Missing request log line for '.$status.' '.$path);
    }

    private function lockInfo(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8" ?>
<D:lockinfo xmlns:D="DAV:">
  <D:lockscope><D:exclusive/></D:lockscope>
  <D:locktype><D:write/></D:locktype>
</D:lockinfo>
XML;
    }

    private function currentUserPrincipal(): string
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

    private function propfind(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8" ?>
<D:propfind xmlns:D="DAV:">
  <D:prop>
    <D:displayname/>
  </D:prop>
</D:propfind>
XML;
    }

    private function calendarQuery(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8" ?>
<C:calendar-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
  <D:prop>
    <D:getetag/>
    <C:calendar-data/>
  </D:prop>
  <C:filter>
    <C:comp-filter name="VCALENDAR">
      <C:comp-filter name="VEVENT"/>
    </C:comp-filter>
  </C:filter>
</C:calendar-query>
XML;
    }

    private function principalSearch(string $property, string $match): string
    {
        if ($property === 'email') {
            return <<<XML
<?xml version="1.0" encoding="utf-8" ?>
<D:principal-property-search xmlns:D="DAV:" xmlns:S="http://sabredav.org/ns">
  <D:property-search>
    <D:prop>
      <S:email-address/>
    </D:prop>
    <D:match>{$match}</D:match>
  </D:property-search>
  <D:prop>
    <D:displayname/>
  </D:prop>
</D:principal-property-search>
XML;
        }

        return <<<XML
<?xml version="1.0" encoding="utf-8" ?>
<D:principal-property-search xmlns:D="DAV:">
  <D:property-search>
    <D:prop>
      <D:displayname/>
    </D:prop>
    <D:match>{$match}</D:match>
  </D:property-search>
  <D:prop>
    <D:displayname/>
  </D:prop>
</D:principal-property-search>
XML;
    }

    private function event(string $uid, string $summary): string
    {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//NexusDAV//EN',
            'BEGIN:VEVENT',
            'UID:'.$uid,
            'DTSTAMP:20261028T120000Z',
            'DTSTART:20261028T130000Z',
            'DTEND:20261028T140000Z',
            'SUMMARY:'.$summary,
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);
    }

    private function card(string $name): string
    {
        return implode("\r\n", [
            'BEGIN:VCARD',
            'VERSION:3.0',
            'UID:bob-card',
            'FN:'.$name,
            'N:Secret;Bob;;;',
            'EMAIL:bob@example.com',
            'END:VCARD',
            '',
        ]);
    }
}
