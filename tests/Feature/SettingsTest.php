<?php

namespace Tests\Feature;

use App\Filament\Pages\Configuration;
use App\Filament\Pages\Settings;
use App\Filament\Widgets\Diagnostics\AppPasswordsTable;
use App\Models\DiagnosticEvent;
use App\Models\Setting;
use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\DiagnosticEventWriter;
use App\Services\SettingsService;
use App\Services\UserService;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * PR 6: panel settings, environment precedence, and what each switch does.
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        // A developer's .env may set LOG_LEVEL or SESSION_LIFETIME. Tests that
        // need a lock set env_set themselves.
        config(['nexusdav.env_set' => []]);
    }

    public function test_a_saved_setting_applies_without_a_restart(): void
    {
        $ada = $this->makeUser('ada');
        $token = $this->token($ada);
        $this->actingAs($this->makeUser('root', true));

        Livewire::test(Configuration::class)
            ->fillForm(['rate_limit_max_attempts' => 2])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Saved');

        $this->assertSame(2, config('nexusdav.rate_limit.max_attempts'));
        $this->assertSame('2', Setting::query()->where('key', 'rate_limit_max_attempts')->value('value'));

        $this->dav('PROPFIND', '/dav/', 'ada', 'x.y')->assertUnauthorized();
        $this->dav('PROPFIND', '/dav/', 'ada', 'x.y')->assertUnauthorized();
        $this->dav('PROPFIND', '/dav/', 'ada', $token)->assertUnauthorized();

        // The next request boots with the default in config and the saved value
        // in the settings cache, and apply() restores the saved value.
        config(['nexusdav.rate_limit.max_attempts' => 5]);
        app(SettingsService::class)->apply();
        $this->assertSame(2, config('nexusdav.rate_limit.max_attempts'));
    }

    public function test_saved_values_are_read_from_the_cache_not_the_database(): void
    {
        $settings = app(SettingsService::class);
        $settings->save(['diagnostics_retention_days' => 9]);
        $settings->forget();
        $settings->apply();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $fresh = new SettingsService;
        $fresh->apply();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(9, config('nexusdav.diagnostics.retention_days'));
        $this->assertSame([], array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'settings')));
    }

    public function test_the_environment_wins_and_its_field_is_read_only(): void
    {
        config([
            'nexusdav.env_set' => ['NEXUSDAV_CALDAV'],
            'nexusdav.features.caldav' => false,
        ]);
        $settings = app(SettingsService::class);

        $settings->save(['caldav' => true, 'carddav' => false]);

        $this->assertFalse($settings->get('caldav'));
        $this->assertTrue($settings->isLocked('caldav'));
        $this->assertSame(0, Setting::query()->where('key', 'caldav')->count());
        $this->assertFalse($settings->get('carddav'));

        $this->actingAs($this->makeUser('root', true));

        Livewire::test(Settings::class)
            ->assertFormFieldDisabled('caldav')
            ->assertFormFieldEnabled('carddav')
            ->assertSee('Set by NEXUSDAV_CALDAV')
            ->fillForm(['caldav' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse(config('nexusdav.features.caldav'));
        $this->assertSame(0, Setting::query()->where('key', 'caldav')->count());
    }

    public function test_a_value_that_is_not_valid_falls_back_to_the_default(): void
    {
        config([
            'nexusdav.rate_limit.max_attempts' => 'abc',
            'nexusdav.default_timezone' => 'Mars/Base',
            'nexusdav.features.notes' => 'perhaps',
        ]);

        app(SettingsService::class)->apply();

        $this->assertSame(5, config('nexusdav.rate_limit.max_attempts'));
        $this->assertSame('UTC', config('nexusdav.default_timezone'));
        $this->assertTrue(config('nexusdav.features.notes'));
    }

    public function test_caldav_off_answers_403_with_a_reason_and_keeps_the_data(): void
    {
        $ada = $this->makeUser('ada');
        $token = $this->token($ada);
        $this->dav('PUT', '/dav/calendars/ada/default/kept.ics', 'ada', $token, ['CONTENT_TYPE' => 'text/calendar'], $this->event('kept', 'VEVENT'))->assertCreated();

        app(SettingsService::class)->save(['caldav' => false]);

        $off = $this->dav('PROPFIND', '/dav/calendars/ada/default/', 'ada', $token);
        $off->assertForbidden();
        $this->assertStringContainsString('CalDAV is turned off on this server.', $this->body($off));
        $this->dav('PROPFIND', '/dav/addressbooks/ada/default/', 'ada', $token)->assertStatus(207);
        $principal = $this->body($this->dav('PROPFIND', '/dav/principals/ada/', 'ada', $token, [], $this->propfind('<c:calendar-home-set/>')));
        $this->assertStringContainsString('404 Not Found', $principal);
        $this->assertStringNotContainsString('/dav/calendars/', $principal);

        app(SettingsService::class)->save(['caldav' => true]);

        $this->dav('GET', '/dav/calendars/ada/default/kept.ics', 'ada', $token)->assertOk();
    }

    public function test_carddav_off_answers_403_with_a_reason(): void
    {
        $ada = $this->makeUser('ada');
        $token = $this->token($ada);

        app(SettingsService::class)->save(['carddav' => false]);

        $off = $this->dav('PROPFIND', '/dav/addressbooks/ada/', 'ada', $token);
        $off->assertForbidden();
        $this->assertStringContainsString('CardDAV is turned off on this server.', $this->body($off));
        $this->dav('PROPFIND', '/dav/calendars/ada/', 'ada', $token)->assertStatus(207);
    }

    public function test_tasks_off_keeps_existing_tasks_readable_but_refuses_new_ones(): void
    {
        $ada = $this->makeUser('ada');
        $token = $this->token($ada);
        $this->dav('PUT', '/dav/calendars/ada/default/task.ics', 'ada', $token, ['CONTENT_TYPE' => 'text/calendar'], $this->event('task', 'VTODO'))->assertCreated();

        app(SettingsService::class)->save(['tasks' => false]);

        $this->dav('GET', '/dav/calendars/ada/default/task.ics', 'ada', $token)->assertOk();
        $this->dav('PUT', '/dav/calendars/ada/default/new-task.ics', 'ada', $token, ['CONTENT_TYPE' => 'text/calendar'], $this->event('new-task', 'VTODO'))->assertForbidden();
        $this->dav('PUT', '/dav/calendars/ada/default/task.ics', 'ada', $token, ['CONTENT_TYPE' => 'text/calendar'], $this->event('task', 'VTODO', 'Changed'))->assertForbidden();
        $this->dav('PUT', '/dav/calendars/ada/default/event.ics', 'ada', $token, ['CONTENT_TYPE' => 'text/calendar'], $this->event('event', 'VEVENT'))->assertCreated();

        $components = $this->body($this->dav('PROPFIND', '/dav/calendars/ada/default/', 'ada', $token, [], $this->propfind('<c:supported-calendar-component-set/>')));
        $this->assertStringContainsString('VEVENT', $components);
        $this->assertStringNotContainsString('VTODO', $components);

        $this->dav('DELETE', '/dav/calendars/ada/default/task.ics', 'ada', $token)->assertNoContent();
        $this->assertSame('VEVENT,VTODO,VJOURNAL', DB::table('calendars')->value('components'));
    }

    public function test_notes_off_refuses_new_notes(): void
    {
        $ada = $this->makeUser('ada');
        $token = $this->token($ada);

        app(SettingsService::class)->save(['notes' => false]);

        $this->dav('PUT', '/dav/calendars/ada/default/note.ics', 'ada', $token, ['CONTENT_TYPE' => 'text/calendar'], $this->event('note', 'VJOURNAL'))->assertForbidden();
        $this->dav('PUT', '/dav/calendars/ada/default/task.ics', 'ada', $token, ['CONTENT_TYPE' => 'text/calendar'], $this->event('task', 'VTODO'))->assertCreated();
    }

    public function test_new_calendars_use_the_owner_or_server_time_zone(): void
    {
        app(SettingsService::class)->save(['timezone' => 'Europe/Berlin']);
        $ada = $this->makeUser('ada');
        $token = $this->token($ada);

        $this->assertStringContainsString('TZID:Europe/Berlin', (string) DB::table('calendarinstances')->where('principaluri', 'principals/ada')->value('timezone'));

        $ada->forceFill(['timezone' => 'Asia/Tokyo'])->save();
        $this->dav('MKCALENDAR', '/dav/calendars/ada/work', 'ada', $token)->assertCreated();
        $timezone = $this->body($this->dav('PROPFIND', '/dav/calendars/ada/work/', 'ada', $token, [], $this->propfind('<c:calendar-timezone/>')));

        $this->assertStringContainsString('TZID:Asia/Tokyo', $timezone);
        $this->assertSame('Europe/Berlin', $this->makeUser('bob')->resolvedTimezone());
    }

    public function test_the_log_level_applies_to_every_channel(): void
    {
        $slack = config('logging.channels.slack.level');

        app(SettingsService::class)->save(['log_level' => 'error']);

        $this->assertSame('error', config('logging.channels.stderr.level'));
        $this->assertSame('error', config('logging.channels.single.level'));
        // Slack keeps its own threshold.
        $this->assertSame($slack, config('logging.channels.slack.level'));
    }

    public function test_session_settings_apply_and_secure_cookies_follow_an_https_app_url(): void
    {
        app(SettingsService::class)->save(['session_lifetime' => 30]);
        $this->assertSame(30, config('session.lifetime'));

        $saved = [getenv('APP_URL'), $_ENV['APP_URL'] ?? null, $_SERVER['APP_URL'] ?? null];

        try {
            putenv('APP_URL=https://dav.example.com');
            $_ENV['APP_URL'] = $_SERVER['APP_URL'] = 'https://dav.example.com';
            $https = require config_path('session.php');
            putenv('APP_URL=http://dav.example.com');
            $_ENV['APP_URL'] = $_SERVER['APP_URL'] = 'http://dav.example.com';
            $http = require config_path('session.php');
        } finally {
            is_string($saved[0]) ? putenv('APP_URL='.$saved[0]) : putenv('APP_URL');
            $_ENV['APP_URL'] = $saved[1];
            $_SERVER['APP_URL'] = $saved[2];
        }

        $this->assertTrue($https['secure']);
        $this->assertFalse($http['secure']);
    }

    public function test_secure_cookies_cannot_be_turned_on_over_plain_http(): void
    {
        $this->actingAs($this->makeUser('root', true));

        Livewire::test(Configuration::class)
            ->fillForm(['session_secure_cookie' => true])
            ->call('save')
            ->assertHasFormErrors(['session_secure_cookie']);

        $this->assertFalse(app(SettingsService::class)->get('session_secure_cookie'));
    }

    public function test_the_retention_setting_decides_what_is_pruned(): void
    {
        app(SettingsService::class)->save(['diagnostics_retention_days' => 7]);
        Cache::forget('nexusdav.diagnostic_events.prune');
        $this->plant('old', now()->subDays(8));
        $this->plant('recent', now()->subDays(6));

        app(DiagnosticEventWriter::class)->recordAuthFailure(DiagnosticEvent::SOURCE_DAV, '192.0.2.1', 'new', null, 'PROPFIND', '/dav/', 401, 'unknown user');

        $this->assertSame(0, DiagnosticEvent::query()->where('username', 'old')->count());
        $this->assertSame(1, DiagnosticEvent::query()->where('username', 'recent')->count());
    }

    public function test_panel_times_use_the_server_zone_format_and_week_start(): void
    {
        $settings = app(SettingsService::class);
        $settings->save(['timezone' => 'America/New_York', 'time_format' => '12', 'week_start' => 0]);

        $this->assertSame('America/New_York', FilamentTimezone::get());
        $this->assertSame(0, DateTimePicker::make('when')->getFirstDayOfWeek());
        $picker = DateTimePicker::make('when');
        $this->assertFalse($picker->isNative());
        $this->assertFalse($picker->hasSeconds());

        $this->travelTo(Carbon::parse('2026-07-01 18:30:05', 'UTC'));
        app(AppPasswordService::class)->issue($this->makeUser('ada'), 'phone');
        $this->travelBack();
        $this->actingAs($this->makeUser('root', true));

        Livewire::test(AppPasswordsTable::class)->assertSee('Jul 1, 2026 2:30:05 PM');

        $settings->save(['time_format' => '24']);
        Livewire::test(AppPasswordsTable::class)->assertSee('Jul 1, 2026 14:30:05');
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

    private function token(User $user): string
    {
        return app(AppPasswordService::class)->issue($user, 'phone')['token'];
    }

    /**
     * @param  array<string, string>  $server
     */
    private function dav(string $method, string $uri, string $username, string $password, array $server = [], ?string $body = null): TestResponse
    {
        return $this->call($method, $uri, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Basic '.base64_encode($username.':'.$password),
            'HTTP_DEPTH' => '0',
            'CONTENT_TYPE' => 'application/xml',
            ...$server,
        ], $body);
    }

    private function body(TestResponse $response): string
    {
        if ($response->baseResponse instanceof StreamedResponse) {
            return $response->streamedContent();
        }

        return (string) $response->getContent();
    }

    private function propfind(string $property): string
    {
        return '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop>'.$property.'</d:prop></d:propfind>';
    }

    private function event(string $uid, string $component, string $summary = 'Item'): string
    {
        $dates = $component === 'VTODO' ? ['DUE:20261102T130000Z'] : ['DTSTART:20261102T130000Z'];

        return implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//NexusDAV//EN',
            'BEGIN:'.$component, 'UID:'.$uid.'@nexusdav.test', 'DTSTAMP:20261101T120000Z',
            ...$dates, 'SUMMARY:'.$summary, 'END:'.$component, 'END:VCALENDAR', '',
        ]);
    }

    private function plant(string $username, Carbon $occurredAt): void
    {
        DiagnosticEvent::query()->insert([
            'occurred_at' => $occurredAt,
            'source' => DiagnosticEvent::SOURCE_DAV,
            'kind' => DiagnosticEvent::KIND_AUTH_FAILED,
            'ip' => '192.0.2.9',
            'username' => $username,
            'public_id' => null,
            'method' => 'PROPFIND',
            'path' => '/dav/',
            'status' => 401,
            'reason' => 'unknown user',
            'repeat_count' => 1,
            'bucket' => intdiv($occurredAt->getTimestamp(), 60),
        ]);
    }
}
