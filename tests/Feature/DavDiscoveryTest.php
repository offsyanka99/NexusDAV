<?php

namespace Tests\Feature;

use App\Models\Principal;
use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class DavDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_user_creates_both_homes_and_default_collections(): void
    {
        $user = $this->makeUser('ada', 'Ada Lovelace', 'ada@example.com');
        $token = $this->token($user);

        $this->assertSame('principals/ada', $user->principal?->uri);
        $this->assertSame(1, DB::table('calendars')->count());
        $this->assertSame(1, DB::table('calendarinstances')->count());
        $this->assertSame('default', DB::table('calendarinstances')->value('uri'));
        $this->assertSame(1, DB::table('addressbooks')->count());
        $this->assertSame('default', DB::table('addressbooks')->value('uri'));

        $this->assertSame(404, $this->dav('PROPFIND', '/dav/calendars/ada/inbox', 'ada', $token, ['Depth' => '0'], $this->discovery())->getStatusCode());
        $this->assertSame(404, $this->dav('PROPFIND', '/dav/calendars/ada/outbox', 'ada', $token, ['Depth' => '0'], $this->discovery())->getStatusCode());

        $calendarHome = $this->dav('PROPFIND', '/dav/calendars/ada', 'ada', $token, ['Depth' => '1'], $this->discovery());
        $calendarHome->assertStatus(207);
        $calendarBody = $this->body($calendarHome);
        $this->assertStringContainsString('/dav/calendars/ada/', $calendarBody);
        $this->assertStringContainsString('/dav/calendars/ada/default/', $calendarBody);

        $addressHome = $this->dav('PROPFIND', '/dav/addressbooks/ada', 'ada', $token, ['Depth' => '1'], $this->discovery());
        $addressHome->assertStatus(207);
        $addressBody = $this->body($addressHome);
        $this->assertStringContainsString('/dav/addressbooks/ada/', $addressBody);
        $this->assertStringContainsString('/dav/addressbooks/ada/default/', $addressBody);
    }

    public function test_backfill_is_idempotent_and_prepare_covers_existing_users(): void
    {
        $users = app(UserService::class);
        $existing = User::withoutEvents(function (): User {
            return User::query()->create([
                'username' => 'ada_l',
                'display_name' => 'Ada Lovelace',
                'email' => 'ada@example.com',
                'password' => null,
                'is_admin' => false,
                'is_active' => true,
            ]);
        });
        $this->assertNull($existing->principal);

        $users->backfillHomeCollections();
        $first = Principal::query()->where('user_id', $existing->getKey())->sole();
        $createdAt = $first->created_at;
        $calendarId = DB::table('calendars')->sole()->id;
        $addressBookId = DB::table('addressbooks')->sole()->id;

        $users->backfillHomeCollections();

        $second = Principal::query()->where('user_id', $existing->getKey())->sole();
        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame('principals/ada_l', $second->uri);
        $this->assertTrue($createdAt?->equalTo($second->created_at) ?? false);
        $this->assertSame(1, Principal::query()->count());

        $token = $this->token($existing);
        $this->dav('PROPFIND', '/dav/calendars/ada_l', 'ada_l', $token, ['Depth' => '0'], $this->discovery())->assertStatus(207);
        $this->dav('PROPFIND', '/dav/addressbooks/ada_l', 'ada_l', $token, ['Depth' => '0'], $this->discovery())->assertStatus(207);

        $admin = User::factory()->create([
            'username' => 'admin',
            'is_admin' => true,
            'is_active' => true,
            'email' => 'admin@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);
        Principal::query()->where('user_id', $admin->getKey())->delete();
        $plain = User::withoutEvents(function (): User {
            return User::query()->create([
                'username' => 'bob',
                'display_name' => 'Bob Baker',
                'email' => 'bob@example.com',
                'password' => null,
                'is_admin' => false,
                'is_active' => true,
            ]);
        });

        $this->setEnv('APP_URL', 'http://localhost:8080');
        $this->setEnv('ADMIN_EMAIL', 'other@example.com');
        $this->setEnv('ADMIN_PASSWORD', 'different-password');

        $this->artisan('nexusdav:prepare')->assertSuccessful();
        $this->artisan('nexusdav:prepare')->assertSuccessful();

        $this->assertSame('principals/admin', $admin->fresh()?->principal?->uri);
        $this->assertSame('principals/bob', $plain->fresh()?->principal?->uri);
        $this->assertSame('principals/ada_l', $existing->fresh()?->principal?->uri);
        $this->assertSame(3, Principal::query()->count());
        $this->assertSame(3, DB::table('calendars')->count());
        $this->assertSame(3, DB::table('calendarinstances')->count());
        $this->assertSame(3, DB::table('addressbooks')->count());
        $this->assertSame($calendarId, DB::table('calendarinstances')->where('principaluri', 'principals/ada_l')->value('calendarid'));
        $this->assertSame($addressBookId, DB::table('addressbooks')->where('principaluri', 'principals/ada_l')->value('id'));
    }

    public function test_deleting_a_user_removes_the_homes(): void
    {
        $ada = $this->makeUser('ada', 'Ada Lovelace', 'ada@example.com');
        $bob = $this->makeUser('bob', 'Bob Baker', 'bob@example.com');
        $bobToken = $this->token($bob);

        $before = $this->dav('PROPFIND', '/dav/calendars/ada', 'bob', $bobToken, ['Depth' => '0'], $this->discovery());
        $this->assertContains($before->getStatusCode(), [403, 404], $this->body($before));

        app(UserService::class)->delete($ada);

        $this->assertNull($ada->fresh());
        $this->assertSame(0, Principal::query()->where('uri', 'principals/ada')->count());

        $calendar = $this->dav('PROPFIND', '/dav/calendars/ada', 'bob', $bobToken, ['Depth' => '0'], $this->discovery());
        $address = $this->dav('PROPFIND', '/dav/addressbooks/ada', 'bob', $bobToken, ['Depth' => '0'], $this->discovery());

        $this->assertSame(404, $calendar->getStatusCode(), $this->body($calendar));
        $this->assertSame(404, $address->getStatusCode(), $this->body($address));
        $this->assertStringNotContainsString('/dav/calendars/ada/', $this->body($calendar));
        $this->assertStringNotContainsString('/dav/addressbooks/ada/', $this->body($address));

        $this->dav('PROPFIND', '/dav/calendars/bob', 'bob', $bobToken, ['Depth' => '0'], $this->discovery())->assertStatus(207);
    }

    public function test_discovery_properties_are_served_to_the_owner_only(): void
    {
        $ada = $this->makeUser('ada', 'Ada Lovelace', 'ada@example.com');
        $bob = $this->makeUser('bob', 'Bob Baker', 'bob@example.com');
        $adaToken = $this->token($ada);
        $bobToken = $this->token($bob);

        $root = $this->dav('PROPFIND', '/dav/', 'ada', $adaToken, ['Depth' => '0'], $this->discovery());
        $root->assertStatus(207);
        $rootBody = $this->body($root);
        $this->assertStringContainsString('/dav/principals/ada/', $rootBody);
        $this->assertStringNotContainsString('/dav/principals/bob', $rootBody);

        $principal = $this->dav('PROPFIND', '/dav/principals/ada', 'ada', $adaToken, ['Depth' => '0'], $this->discovery());
        $principal->assertStatus(207);
        $principalBody = $this->body($principal);
        $this->assertStringContainsString('/dav/calendars/ada/', $principalBody);
        $this->assertStringContainsString('/dav/addressbooks/ada/', $principalBody);
        $this->assertStringNotContainsString('/dav/calendars/bob', $principalBody);
        $this->assertStringNotContainsString('/dav/addressbooks/bob', $principalBody);

        $calendarSync = $this->sync('/dav/calendars/ada', 'ada', $adaToken);
        $calendarSync->assertStatus(207);
        $calendarToken = $this->syncToken($calendarSync);
        $this->assertStringContainsString('sync-collection', $this->body(
            $this->dav('PROPFIND', '/dav/calendars/ada', 'ada', $adaToken, ['Depth' => '0'], $this->reports()),
        ));

        $addressSync = $this->sync('/dav/addressbooks/ada', 'ada', $adaToken);
        $addressSync->assertStatus(207);
        $addressToken = $this->syncToken($addressSync);

        $calendarAgain = $this->sync('/dav/calendars/ada', 'ada', $adaToken, $calendarToken);
        $calendarAgain->assertStatus(207);
        $this->assertStringNotContainsString('/dav/calendars/ada/inbox', $this->body($calendarAgain));

        $addressAgain = $this->sync('/dav/addressbooks/ada', 'ada', $adaToken, $addressToken);
        $addressAgain->assertStatus(207);
        $this->assertStringNotContainsString('<d:response', strtolower($this->body($addressAgain)));

        $created = $this->dav('MKCALENDAR', '/dav/calendars/ada/personal', 'ada', $adaToken);
        $created->assertStatus(201);

        $components = $this->dav('PROPFIND', '/dav/calendars/ada/personal', 'ada', $adaToken, ['Depth' => '0'], $this->discovery());
        $components->assertStatus(207);
        $componentBody = $this->body($components);
        $this->assertStringContainsString('VEVENT', $componentBody);
        $this->assertStringContainsString('VTODO', $componentBody);
        $this->assertStringContainsString('VJOURNAL', $componentBody);

        $afterCreate = $this->sync('/dav/calendars/ada', 'ada', $adaToken, $calendarToken);
        $afterCreate->assertStatus(207);
        $this->assertStringContainsString('/dav/calendars/ada/personal', $this->body($afterCreate));

        foreach ([
            '/dav/principals/ada',
            '/dav/calendars/ada',
            '/dav/addressbooks/ada',
            '/dav/calendars/ada/personal',
            '/dav/calendars/ada/inbox',
        ] as $path) {
            $denied = $this->dav('PROPFIND', $path, 'bob', $bobToken, ['Depth' => '0'], $this->discovery());
            $this->assertContains($denied->getStatusCode(), [403, 404], $this->body($denied));
            $deniedBody = $this->body($denied);
            $this->assertDoesNotMatchRegularExpression('#<(?:[A-Za-z0-9]+:)?calendar-home-set\b#', $deniedBody);
            $this->assertDoesNotMatchRegularExpression('#<(?:[A-Za-z0-9]+:)?addressbook-home-set\b#', $deniedBody);
            $this->assertStringNotContainsString('VEVENT', $deniedBody);
        }

        $bobPrincipal = $this->dav('PROPFIND', '/dav/principals/bob', 'bob', $bobToken, ['Depth' => '0'], $this->discovery());
        $bobPrincipal->assertStatus(207);
        $bobBody = $this->body($bobPrincipal);
        $this->assertStringContainsString('/dav/calendars/bob/', $bobBody);
        $this->assertStringContainsString('/dav/addressbooks/bob/', $bobBody);
        $this->assertStringNotContainsString('/dav/calendars/ada', $bobBody);
        $this->assertStringNotContainsString('/dav/addressbooks/ada', $bobBody);
        $this->assertStringNotContainsString('/dav/principals/ada', $bobBody);

        $listing = $this->dav('PROPFIND', '/dav/calendars/', 'bob', $bobToken, ['Depth' => '1'], $this->discovery());
        $listing->assertStatus(207);
        $listed = $this->body($listing);
        $this->assertStringContainsString('/dav/calendars/bob/', $listed);
        $this->assertStringNotContainsString('/dav/calendars/ada', $listed);
    }

    public function test_a_numeric_collection_name_round_trips_in_the_home_sync_token(): void
    {
        $user = $this->makeUser('ada', 'Ada Lovelace', 'ada@example.com');
        $token = $this->token($user);

        $this->dav('MKCALENDAR', '/dav/calendars/ada/2024', 'ada', $token)->assertStatus(201);
        $this->dav('MKCOL', '/dav/addressbooks/ada/2024', 'ada', $token, [
            'Content-Type' => 'application/xml',
        ], $this->addressBook())->assertStatus(201);

        $calendar = $this->sync('/dav/calendars/ada', 'ada', $token);
        $calendar->assertStatus(207);
        $this->assertStringContainsString('/dav/calendars/ada/2024', $this->body($calendar));

        $calendarAgain = $this->sync('/dav/calendars/ada', 'ada', $token, $this->syncToken($calendar));
        $calendarAgain->assertStatus(207);
        $this->assertStringNotContainsString('/dav/calendars/ada/2024', $this->body($calendarAgain));

        $address = $this->sync('/dav/addressbooks/ada', 'ada', $token);
        $address->assertStatus(207);
        $this->assertStringContainsString('/dav/addressbooks/ada/2024', $this->body($address));

        $addressAgain = $this->sync('/dav/addressbooks/ada', 'ada', $token, $this->syncToken($address));
        $addressAgain->assertStatus(207);
        $this->assertStringNotContainsString('/dav/addressbooks/ada/2024', $this->body($addressAgain));
    }

    public function test_reserved_calendar_names_are_rejected_and_leave_no_row(): void
    {
        $user = $this->makeUser('ada', 'Ada Lovelace', 'ada@example.com');
        $token = $this->token($user);

        foreach (['inbox', 'outbox', 'notifications'] as $name) {
            $created = $this->dav('MKCALENDAR', '/dav/calendars/ada/'.$name, 'ada', $token);
            $this->assertSame(405, $created->getStatusCode(), $this->body($created));

            $hidden = $this->dav('PROPFIND', '/dav/calendars/ada/'.$name, 'ada', $token, ['Depth' => '0'], $this->discovery());
            $this->assertSame(404, $hidden->getStatusCode(), $this->body($hidden));
        }

        $this->assertSame(1, DB::table('calendars')->count());
        $this->assertSame(['default'], DB::table('calendarinstances')->pluck('uri')->all());
    }

    public function test_supported_components_follow_the_feature_flags(): void
    {
        $user = $this->makeUser('ada', 'Ada Lovelace', 'ada@example.com');
        $token = $this->token($user);

        config([
            'nexusdav.features.tasks' => false,
            'nexusdav.features.notes' => false,
        ]);

        $this->dav('MKCALENDAR', '/dav/calendars/ada/personal', 'ada', $token)->assertStatus(201);

        $components = $this->dav('PROPFIND', '/dav/calendars/ada/personal', 'ada', $token, ['Depth' => '0'], $this->discovery());
        $components->assertStatus(207);
        $body = $this->body($components);
        $this->assertStringContainsString('VEVENT', $body);
        $this->assertStringNotContainsString('VTODO', $body);
        $this->assertStringNotContainsString('VJOURNAL', $body);

        config(['nexusdav.features.carddav' => false]);

        $principal = $this->dav('PROPFIND', '/dav/principals/ada', 'ada', $token, ['Depth' => '0'], $this->discovery());
        $principal->assertStatus(207);
        $principalBody = $this->body($principal);
        $this->assertStringContainsString('/dav/calendars/ada/', $principalBody);
        $this->assertStringNotContainsString('/dav/addressbooks/ada', $principalBody);

        $missing = $this->dav('PROPFIND', '/dav/addressbooks/ada', 'ada', $token, ['Depth' => '0'], $this->discovery());
        $this->assertContains($missing->getStatusCode(), [403, 404], $this->body($missing));
    }

    private function makeUser(string $username, string $displayName, string $email): User
    {
        return app(UserService::class)->create([
            'username' => $username,
            'display_name' => $displayName,
            'email' => $email,
            'password' => 'correct-horse-battery',
        ]);
    }

    private function token(User $user): string
    {
        return app(AppPasswordService::class)->issue($user, 'phone')['token'];
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function dav(string $method, string $uri, ?string $username = null, ?string $password = null, array $headers = [], ?string $body = null): TestResponse
    {
        $server = [];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        if ($username !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Basic '.base64_encode($username.':'.(string) $password);
        }

        return $this->call($method, $uri, [], [], [], $server, $body);
    }

    private function sync(string $uri, string $username, string $password, ?string $token = null): TestResponse
    {
        $syncToken = $token === null ? '' : htmlspecialchars($token, ENT_XML1);

        return $this->dav('REPORT', $uri, $username, $password, [
            'Depth' => '0',
            'Content-Type' => 'application/xml',
        ], <<<XML
<?xml version="1.0" encoding="utf-8" ?>
<D:sync-collection xmlns:D="DAV:">
  <D:sync-token>{$syncToken}</D:sync-token>
  <D:sync-level>1</D:sync-level>
  <D:prop>
    <D:getetag/>
    <D:displayname/>
  </D:prop>
</D:sync-collection>
XML);
    }

    private function syncToken(TestResponse $response): string
    {
        $body = $this->body($response);

        $this->assertSame(1, preg_match('#<(?:[A-Za-z0-9]+:)?sync-token>([^<]+)</(?:[A-Za-z0-9]+:)?sync-token>#', $body, $matches), $body);

        return $matches[1];
    }

    private function body(TestResponse $response): string
    {
        if ($response->baseResponse instanceof StreamedResponse) {
            return $response->streamedContent();
        }

        return (string) $response->getContent();
    }

    private function discovery(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8" ?>
<D:propfind xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav" xmlns:A="urn:ietf:params:xml:ns:carddav">
  <D:prop>
    <D:resourcetype/>
    <D:displayname/>
    <D:current-user-principal/>
    <D:current-user-privilege-set/>
    <D:supported-report-set/>
    <D:sync-token/>
    <C:calendar-home-set/>
    <C:supported-calendar-component-set/>
    <A:addressbook-home-set/>
  </D:prop>
</D:propfind>
XML;
    }

    private function addressBook(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8" ?>
<D:mkcol xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">
  <D:set>
    <D:prop>
      <D:resourcetype>
        <D:collection/>
        <C:addressbook/>
      </D:resourcetype>
    </D:prop>
  </D:set>
</D:mkcol>
XML;
    }

    private function reports(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8" ?>
<D:propfind xmlns:D="DAV:">
  <D:prop>
    <D:supported-report-set/>
  </D:prop>
</D:propfind>
XML;
    }

    private function setEnv(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
