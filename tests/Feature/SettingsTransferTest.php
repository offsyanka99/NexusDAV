<?php

namespace Tests\Feature;

use App\Filament\Pages\Configuration;
use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use App\Services\Push\PushKeys;
use App\Services\SettingsService;
use App\Services\SettingsTransfer;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PR 44 and PR 45 of the Version 1.x.x plan: the panel settings exported to
 * and imported from a JSON file.
 */
class SettingsTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['nexusdav.env_set' => [], 'app.url' => 'https://dav.example.org', 'nexusdav.version' => '1.6.0']);
    }

    public function test_defaults_are_exported_as_null_and_changes_as_their_value(): void
    {
        $this->travelTo(now()->setDate(2026, 12, 1)->setTime(18, 0));
        $file = app(SettingsTransfer::class)->export();

        $this->assertSame('nexusdav-settings', $file['format']);
        $this->assertSame(1, $file['format_version']);
        $this->assertSame('1.6.0', $file['app_version']);
        $this->assertSame('2026-12-01T18:00:00Z', $file['exported_at']);
        $this->assertSame(array_keys(SettingsService::DEFINITIONS), array_keys($file['settings']));
        $this->assertSame([], array_filter($file['settings'], static fn (mixed $value): bool => $value !== null));

        app(SettingsService::class)->save(['files' => true, 'files_max_upload_mb' => 512, 'log_level' => 'warning', 'week_start' => 0]);
        $settings = app(SettingsTransfer::class)->export()['settings'];

        $this->assertTrue($settings['files']);
        $this->assertSame(512, $settings['files_max_upload_mb']);
        $this->assertSame('warning', $settings['log_level']);
        $this->assertSame(0, $settings['week_start']);
        $this->assertNull($settings['caldav']);
    }

    public function test_saving_the_pages_without_a_change_keeps_the_defaults_null(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(Settings::class)->call('save')->assertHasNoFormErrors();
        Livewire::test(Configuration::class)->call('save')->assertHasNoFormErrors();
        $this->assertGreaterThanOrEqual(20, Setting::query()->count());

        $settings = app(SettingsTransfer::class)->export()['settings'];

        // Secure cookies has no fixed default (it follows APP_URL), so a saved value is kept.
        $this->assertSame(app(SettingsService::class)->get('session_secure_cookie'), $settings['session_secure_cookie']);
        unset($settings['session_secure_cookie']);
        $this->assertSame([], array_filter($settings, static fn (mixed $value): bool => $value !== null));
    }

    public function test_locked_secret_and_other_rows_are_never_exported(): void
    {
        $keys = app(PushKeys::class);
        $public = $keys->vapidPublicKey();
        $topicKey = $keys->topicKey();
        $this->assertGreaterThanOrEqual(2, DB::table('settings')->count());
        config(['nexusdav.env_set' => ['LOG_LEVEL', 'NEXUSDAV_FILES']]);

        $json = json_encode(app(SettingsTransfer::class)->export(), JSON_THROW_ON_ERROR);
        $settings = app(SettingsTransfer::class)->export()['settings'];

        $this->assertArrayNotHasKey('log_level', $settings);
        $this->assertArrayNotHasKey('files', $settings);
        $this->assertStringNotContainsString('push.', $json);
        $this->assertStringNotContainsString($public, $json);
        $this->assertStringNotContainsString($topicKey, $json);

        $definitions = SettingsService::DEFINITIONS;
        $definitions['session_lifetime']['secret'] = true;
        $transfer = new SettingsTransfer(app(SettingsService::class), $definitions);
        app(SettingsService::class)->save(['session_lifetime' => 30]);

        $this->assertArrayNotHasKey('session_lifetime', $transfer->export()['settings']);
        $this->assertSame(['session_lifetime' => SettingsTransfer::SKIP_SECRET], $transfer->plan(['session_lifetime' => 60], true)['skipped']);
    }

    public function test_the_page_downloads_the_file_and_logs_it(): void
    {
        $this->travelTo(now()->setDate(2026, 12, 1)->setTime(18, 0));
        $this->actingAs($this->admin());
        $logged = $this->captureLog();
        app(SettingsService::class)->save(['sharing' => false]);

        $response = Livewire::test(Configuration::class)
            ->assertSee('Settings backup')
            ->callAction($this->action('exportSettings'))
            ->assertFileDownloaded('nexusdav-settings-dav.example.org-2026-12-01.json');

        $download = $response->effects['download'] ?? [];
        $file = json_decode(base64_decode((string) ($download['content'] ?? '')), true);
        $this->assertIsArray($file);
        $this->assertFalse($file['settings']['sharing']);

        $this->assertContains(['info', 'Settings exported', ['admin' => 'root', 'settings' => count(SettingsService::DEFINITIONS)]], $logged->lines);
    }

    public function test_export_then_import_gives_the_same_settings_on_another_server(): void
    {
        $settings = app(SettingsService::class);
        $settings->save(['files' => true, 'files_max_upload_mb' => 512, 'timezone' => 'America/Toronto', 'default_locale' => 'ru', 'push' => true]);
        $file = json_encode(app(SettingsTransfer::class)->export(), JSON_THROW_ON_ERROR);
        $before = $this->current();

        // The other server: nothing saved, all defaults.
        Setting::query()->delete();
        $settings->forget();
        $settings->apply();
        $this->assertSame(128, config('nexusdav.files.max_upload_mb'));

        $transfer = app(SettingsTransfer::class);
        $changed = $transfer->import($transfer->parse($file), true);

        $this->assertEqualsCanonicalizing(['files', 'files_max_upload_mb', 'timezone', 'default_locale', 'push'], $changed);
        $this->assertSame($before, $this->current());
        $this->assertSame(512, config('nexusdav.files.max_upload_mb'));
        $this->assertSame('America/Toronto', config('nexusdav.default_timezone'));
    }

    public function test_null_brings_the_default_back_here_and_in_a_running_worker(): void
    {
        $settings = app(SettingsService::class);
        $settings->save(['files_max_upload_mb' => 512, 'log_level' => 'error']);
        $this->assertSame(512, config('nexusdav.files.max_upload_mb'));

        $changed = app(SettingsTransfer::class)->import(['files_max_upload_mb' => null, 'log_level' => null, 'caldav' => null], true);

        $this->assertSame(['files_max_upload_mb', 'log_level'], $changed);
        $this->assertSame(128, config('nexusdav.files.max_upload_mb'));
        $this->assertSame('debug', config('logging.channels.stderr.level'));
        $this->assertFalse(Setting::query()->where('key', 'files_max_upload_mb')->exists());

        // A worker applied 256 earlier; the panel's import then removed the row.
        $settings->save(['files_max_upload_mb' => 256]);
        Setting::query()->where('key', 'files_max_upload_mb')->delete();
        $settings->reload();

        $this->assertSame(128, config('nexusdav.files.max_upload_mb'));
    }

    public function test_settings_that_cannot_be_imported_are_skipped_with_a_reason(): void
    {
        config(['nexusdav.env_set' => ['LOG_LEVEL']]);
        $transfer = app(SettingsTransfer::class);

        $plan = $transfer->plan([
            'log_level' => 'error',
            'default_locale' => 'fr',
            'files_max_upload_mb' => 999999,
            'smtp_password' => 'secret',
            'session_secure_cookie' => true,
            'caldav' => true,
            'sharing' => false,
        ], false);

        $this->assertSame(['sharing' => false], $plan['changes']);
        $this->assertSame(['caldav'], $plan['same']);
        $this->assertSame([
            'log_level' => SettingsTransfer::SKIP_ENVIRONMENT,
            'default_locale' => SettingsTransfer::SKIP_INVALID,
            'files_max_upload_mb' => SettingsTransfer::SKIP_INVALID,
            'smtp_password' => SettingsTransfer::SKIP_UNKNOWN,
            'session_secure_cookie' => SettingsTransfer::SKIP_HTTPS,
        ], $plan['skipped']);

        // Over HTTPS, Secure cookies can be turned on.
        $this->assertArrayHasKey('session_secure_cookie', $transfer->plan(['session_secure_cookie' => true], true)['changes']);
    }

    public function test_files_that_are_not_valid_are_refused_as_a_whole(): void
    {
        $transfer = app(SettingsTransfer::class);

        foreach ([
            'not json' => 'This is not a NexusDAV settings file.',
            '[]' => 'This is not a NexusDAV settings file.',
            '{"format":"other","format_version":1,"settings":{}}' => 'This is not a NexusDAV settings file.',
            '{"format":"nexusdav-settings","format_version":1}' => 'This is not a NexusDAV settings file.',
            '{"format":"nexusdav-settings","format_version":1,"settings":[1,2]}' => 'This is not a NexusDAV settings file.',
            '{"format":"nexusdav-settings","format_version":"1","settings":{}}' => 'This is not a NexusDAV settings file.',
            '{"format":"nexusdav-settings","format_version":2,"app_version":"2.1.0","settings":{}}' => 'This file comes from a newer NexusDAV (2.1.0). Upgrade this server first.',
            str_repeat(' ', SettingsTransfer::MAX_BYTES + 1) => 'The file is larger than 256 KB.',
        ] as $contents => $message) {
            try {
                $transfer->parse((string) $contents);
                $this->fail('Expected: '.$message);
            } catch (InvalidArgumentException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }

        $this->assertSame([], $transfer->parse('{"format":"nexusdav-settings","format_version":1,"settings":{}}'));
    }

    public function test_the_page_shows_the_changes_and_saves_only_on_apply(): void
    {
        $this->actingAs($this->admin());
        $logged = $this->captureLog();
        config(['nexusdav.env_set' => ['NEXUSDAV_PUSH']]);
        $file = $this->file(['files' => true, 'files_max_upload_mb' => 512, 'push' => true, 'unknown_key' => 1, 'caldav' => null]);

        $page = Livewire::test(Configuration::class)
            ->callAction($this->action('importSettings'), data: ['file' => $file])
            ->assertActionMounted('applyImport')
            ->assertMountedActionModalSee('Maximum upload size')
            ->assertMountedActionModalSee('128 → 512')
            ->assertMountedActionModalSee('Off → On')
            ->assertMountedActionModalSee('Set by the environment on this server')
            ->assertMountedActionModalSee('Unknown setting')
            ->assertMountedActionModalSee('1 setting is already the same.');

        $this->assertSame(0, Setting::query()->count());
        $this->assertSame(128, config('nexusdav.files.max_upload_mb'));

        $page->callMountedAction()
            ->assertNotified('Settings imported')
            ->assertSet('importedSettings', null);

        $this->assertSame(512, app(SettingsService::class)->get('files_max_upload_mb'));
        $this->assertTrue(app(SettingsService::class)->get('files'));
        $this->assertFalse(Setting::query()->where('key', 'push')->exists());
        $this->assertContains(['notice', 'Settings imported', ['admin' => 'root', 'settings' => ['files', 'files_max_upload_mb']]], $logged->lines);
    }

    public function test_a_refused_file_keeps_the_upload_open_and_changes_nothing(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(Configuration::class)
            ->callAction($this->action('importSettings'), data: ['file' => UploadedFile::fake()->createWithContent('settings.json', '{"format":"nexusdav-settings","format_version":9,"app_version":"9.0.0","settings":{}}')])
            ->assertNotified('Could not import')
            ->assertActionMounted($this->action('importSettings'));

        Livewire::test(Configuration::class)
            ->callAction($this->action('importSettings'), data: ['file' => $this->file(['caldav' => null])])
            ->assertActionMounted('applyImport')
            ->assertMountedActionModalSee('Nothing to change: this server already has these settings.');

        $this->assertSame(0, Setting::query()->count());
    }

    public function test_an_imported_server_language_reloads_the_page(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(Configuration::class)
            ->callAction($this->action('importSettings'), data: ['file' => $this->file(['default_locale' => 'ru'])])
            ->callMountedAction()
            ->assertRedirect(Configuration::getUrl());

        $this->assertSame('ru', app(SettingsService::class)->get('default_locale'));
    }

    private function action(string $name): TestAction
    {
        return TestAction::make($name)->schemaComponent('settingsBackup', 'content');
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function file(array $settings): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('settings.json', (string) json_encode([
            'format' => 'nexusdav-settings',
            'format_version' => 1,
            'app_version' => '1.6.0',
            'exported_at' => '2026-12-01T18:00:00Z',
            'settings' => $settings,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function current(): array
    {
        $values = [];

        foreach (array_keys(SettingsService::DEFINITIONS) as $key) {
            $values[$key] = app(SettingsService::class)->get($key);
        }

        return $values;
    }

    private function admin(): User
    {
        return User::factory()->create(['username' => 'root', 'is_admin' => true, 'is_active' => true]);
    }

    private function captureLog(): object
    {
        $logged = new \stdClass;
        $logged->lines = [];

        Log::listen(function (MessageLogged $event) use ($logged): void {
            if (str_starts_with($event->message, 'Settings ')) {
                $logged->lines[] = [$event->level, $event->message, $event->context];
            }
        });

        return $logged;
    }
}
