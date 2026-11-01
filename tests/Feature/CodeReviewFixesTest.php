<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\RelationManagers\CalendarsRelationManager;
use App\Models\CalendarInstance;
use App\Models\DiagnosticEvent;
use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\DiagnosticEventWriter;
use App\Services\UserService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Regressions found in the review of PR 1 to PR 5.
 */
class CodeReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_events_after_2038_are_stored(): void
    {
        $ada = $this->makeUser('ada', 'ada@example.com');
        $token = $this->token($ada);

        $single = $this->dav('PUT', '/dav/calendars/ada/default/future.ics', 'ada', $token, [
            'Content-Type' => 'text/calendar',
        ], $this->event('future@nexusdav.test', 'DTSTART:20400101T130000Z', 'DTEND:20400101T140000Z'));
        $this->assertSame(201, $single->getStatusCode(), $this->body($single));

        $recurring = $this->dav('PUT', '/dav/calendars/ada/default/until.ics', 'ada', $token, [
            'Content-Type' => 'text/calendar',
        ], $this->event('until@nexusdav.test', 'DTSTART:20361101T130000Z', 'DTEND:20361101T140000Z', 'RRULE:FREQ=YEARLY;UNTIL=20451101T130000Z'));
        $this->assertSame(201, $recurring->getStatusCode(), $this->body($recurring));

        $stored = DB::table('calendarobjects')->where('uri', 'future.ics')->first();
        $this->assertNotNull($stored);
        $this->assertGreaterThan(2147483647, (int) $stored->firstoccurence);

        $got = $this->dav('GET', '/dav/calendars/ada/default/future.ics', 'ada', $token);
        $got->assertOk();
        $this->assertStringContainsString('20400101T130000Z', $this->body($got));
    }

    public function test_another_user_looks_the_same_as_a_missing_user(): void
    {
        $ada = $this->makeUser('ada', 'ada@example.com');
        $this->makeUser('bob', 'bob@example.com');
        $token = $this->token($ada);

        foreach (['calendars', 'addressbooks', 'principals'] as $root) {
            $existing = $this->dav('PROPFIND', '/dav/'.$root.'/bob/', 'ada', $token, ['Depth' => '0']);
            $missing = $this->dav('PROPFIND', '/dav/'.$root.'/nobody/', 'ada', $token, ['Depth' => '0']);

            $this->assertSame(404, $existing->getStatusCode(), $root);
            $this->assertSame(404, $missing->getStatusCode(), $root);
            $this->assertStringNotContainsString('bob@example.com', $this->body($existing));

            $this->dav('PROPFIND', '/dav/'.$root.'/ada/', 'ada', $token, ['Depth' => '0'])->assertStatus(207);
        }
    }

    public function test_a_malformed_home_sync_token_is_refused_without_a_server_error(): void
    {
        $ada = $this->makeUser('ada', 'ada@example.com');
        $token = $this->token($ada);

        foreach (['[{"a":"b","c":"d"}]', '{"x":["default",""]}', '[["default"]]'] as $json) {
            $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

            foreach (['/dav/calendars/ada/', '/dav/addressbooks/ada/'] as $home) {
                $response = $this->dav('REPORT', $home, 'ada', $token, [
                    'Depth' => '0',
                    'Content-Type' => 'application/xml',
                ], $this->syncCollection('http://sabre.io/ns/sync/home-1.'.$encoded));

                $this->assertSame(403, $response->getStatusCode(), $home.' '.$json.' '.$this->body($response));
                $this->assertStringContainsString('valid-sync-token', $this->body($response));
            }
        }
    }

    public function test_email_is_unique_without_regard_to_case(): void
    {
        $users = app(UserService::class);
        $bob = $this->makeUser('bob', 'Bob@Example.com');

        try {
            $this->makeUser('bobby', 'bob@example.com');
            $this->fail('A second account with the same email in another case was created.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(UserService::EMAIL_TAKEN, $exception->getMessage());
        }

        $ada = $this->makeUser('ada', 'ada@example.com');

        try {
            $users->update($ada, ['email' => 'BOB@example.com']);
            $this->fail('An update took another account\'s email in another case.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(UserService::EMAIL_TAKEN, $exception->getMessage());
        }

        $users->update($bob, ['email' => 'bob@example.com']);
        $this->assertSame('bob@example.com', $bob->fresh()?->email);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->makeUser('root', 'root@example.com', true));

        Livewire::test(CreateUser::class)
            ->fillForm([
                'username' => 'robert',
                'display_name' => 'Robert',
                'email' => 'BOB@EXAMPLE.COM',
                'is_admin' => false,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['email']);

        Livewire::test(EditUser::class, ['record' => $bob->getKey()])
            ->fillForm(['email' => 'Bob@example.com'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(1, User::query()->whereRaw('lower(email) = ?', ['bob@example.com'])->count());
    }

    public function test_principal_display_name_over_255_characters_is_refused(): void
    {
        $ada = $this->makeUser('ada', 'ada@example.com');
        $token = $this->token($ada);
        $body = '<?xml version="1.0"?><d:propertyupdate xmlns:d="DAV:"><d:set><d:prop><d:displayname>'
            .str_repeat('x', 256)
            .'</d:displayname></d:prop></d:set></d:propertyupdate>';

        $response = $this->dav('PROPPATCH', '/dav/principals/ada/', 'ada', $token, ['Content-Type' => 'application/xml'], $body);

        $response->assertStatus(207);
        $this->assertStringContainsString('403', $this->body($response));
        $this->assertSame('Ada', $ada->fresh()?->display_name);
    }

    public function test_a_calendar_with_a_client_alpha_color_can_be_edited_in_the_panel(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = $this->makeUser('root', 'root@example.com', true);
        $ada = $this->makeUser('ada', 'ada@example.com');
        DB::table('calendarinstances')
            ->where('principaluri', 'principals/ada')
            ->update(['calendarcolor' => '#ff2968FF']);
        $calendar = CalendarInstance::query()->where('principaluri', 'principals/ada')->firstOrFail();

        $this->actingAs($admin);

        Livewire::test(CalendarsRelationManager::class, [
            'ownerRecord' => $ada,
            'pageClass' => EditUser::class,
        ])
            ->mountTableAction('edit', $calendar)
            ->assertTableActionDataSet(['color' => '#FF2968'])
            ->setTableActionData(['display_name' => 'Renamed'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $calendar->refresh();
        $this->assertSame('Renamed', $calendar->displayname);
        $this->assertSame('#FF2968', $calendar->calendarcolor);
    }

    public function test_a_zero_collapse_window_does_not_divide_by_zero(): void
    {
        config(['nexusdav.diagnostics.collapse_seconds' => 0]);

        app(DiagnosticEventWriter::class)->recordAuthFailure(
            DiagnosticEvent::SOURCE_DAV,
            '192.0.2.10',
            'ada',
            null,
            'PROPFIND',
            '/dav/',
            401,
            'unknown user',
        );

        $this->assertSame(1, DiagnosticEvent::query()->count());
    }

    private function makeUser(string $username, string $email, bool $admin = false): User
    {
        return app(UserService::class)->create([
            'username' => $username,
            'display_name' => ucfirst($username),
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
     */
    private function dav(string $method, string $uri, string $username, string $password, array $headers = [], ?string $body = null): TestResponse
    {
        $server = ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode($username.':'.$password)];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call($method, $uri, [], [], [], $server, $body);
    }

    private function body(TestResponse $response): string
    {
        if ($response->baseResponse instanceof StreamedResponse) {
            return $response->streamedContent();
        }

        return (string) $response->getContent();
    }

    private function event(string $uid, string ...$lines): string
    {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//NexusDAV//EN',
            'BEGIN:VEVENT',
            'UID:'.$uid,
            'DTSTAMP:20261031T120000Z',
            ...$lines,
            'SUMMARY:Later',
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);
    }

    private function syncCollection(string $token): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<d:sync-collection xmlns:d="DAV:">'
            .'<d:sync-token>'.$token.'</d:sync-token>'
            .'<d:sync-level>1</d:sync-level>'
            .'<d:prop><d:getetag/></d:prop>'
            .'</d:sync-collection>';
    }
}
