<?php

namespace Tests\Feature;

use App\Filament\Pages\Diagnostics;
use App\Filament\Pages\Settings;
use App\Filament\Widgets\AttentionWidget;
use App\Filament\Widgets\Diagnostics\PushSubscriptionsTable;
use App\Filament\Widgets\Diagnostics\QueueWorkerCard;
use App\Filament\Widgets\ServicesWidget;
use App\Models\PushSubscription;
use App\Models\Setting;
use App\Models\User;
use App\Services\Push\PushConfig;
use App\Services\SettingsService;
use App\Services\UserService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PR 38: WebDAV-Push on the Settings page, the subscriptions and the queue
 * worker on Diagnostics, and the worker on the Dashboard.
 */
class PushAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $root;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['nexusdav.env_set' => [], 'nexusdav.features.push' => false]);

        $this->root = app(UserService::class)->create([
            'username' => 'root',
            'display_name' => 'Root',
            'email' => 'root@example.com',
            'password' => 'correct horse battery staple',
            'is_admin' => true,
        ]);
        $this->actingAs($this->root);
    }

    public function test_push_and_its_options_are_set_on_the_settings_page(): void
    {
        Livewire::test(Settings::class)
            ->assertSee('WebDAV-Push')
            ->assertSee('The messages are sent by the queue worker, which must be running.')
            ->assertFormFieldEnabled('push')
            ->assertFormSet(['push' => false])
            ->assertFormFieldHidden('push_files')
            ->assertFormFieldHidden('push_max_subscriptions')
            ->assertFormFieldHidden('push_allow_private')
            ->assertFormFieldHidden('push_log_level')
            ->fillForm(['push' => true])
            ->assertFormFieldVisible('push_files')
            ->assertFormFieldVisible('push_max_subscriptions')
            ->assertFormFieldVisible('push_allow_private')
            ->assertFormFieldVisible('push_log_level')
            ->assertFormSet(['push_files' => false, 'push_max_subscriptions' => 100, 'push_allow_private' => false, 'push_log_level' => 'info'])
            ->fillForm(['push_files' => true, 'push_max_subscriptions' => 20, 'push_allow_private' => true, 'push_log_level' => 'debug'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Saved');

        $settings = app(SettingsService::class);
        $this->assertTrue($settings->get('push'));
        $this->assertTrue($settings->get('push_files'));
        $this->assertSame(20, $settings->get('push_max_subscriptions'));
        $this->assertTrue($settings->get('push_allow_private'));
        $this->assertSame('debug', $settings->get('push_log_level'));

        Livewire::test(Settings::class)
            ->fillForm(['push_max_subscriptions' => 0])
            ->call('save')
            ->assertHasFormErrors(['push_max_subscriptions']);

        // Switching push off keeps its options.
        Livewire::test(Settings::class)
            ->fillForm(['push' => false])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertFalse($settings->get('push'));
        $this->assertSame(20, $settings->get('push_max_subscriptions'));
    }

    public function test_set_variables_lock_the_push_fields(): void
    {
        config([
            'nexusdav.env_set' => ['NEXUSDAV_PUSH', 'NEXUSDAV_PUSH_FILES', 'NEXUSDAV_PUSH_MAX_SUBSCRIPTIONS', 'NEXUSDAV_PUSH_ALLOW_PRIVATE', 'NEXUSDAV_PUSH_LOG_LEVEL'],
            'nexusdav.features.push' => true,
            'nexusdav.push.max_subscriptions' => 7,
        ]);

        Livewire::test(Settings::class)
            ->assertFormFieldDisabled('push')
            ->assertFormFieldDisabled('push_files')
            ->assertFormFieldDisabled('push_max_subscriptions')
            ->assertFormFieldDisabled('push_allow_private')
            ->assertFormFieldDisabled('push_log_level')
            ->assertSee('Set by NEXUSDAV_PUSH')
            ->assertSee('Set by NEXUSDAV_PUSH_MAX_SUBSCRIPTIONS')
            ->fillForm(['push' => false, 'push_max_subscriptions' => 50])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(app(SettingsService::class)->get('push'));
        $this->assertSame(7, app(SettingsService::class)->get('push_max_subscriptions'));
        $this->assertSame(0, Setting::query()->where('key', 'like', 'push%')->count());
    }

    public function test_the_push_switch_says_whether_the_worker_runs(): void
    {
        Livewire::test(Settings::class)->assertSee('No queue worker has run yet. Start it before turning push on');

        $this->beat(now()->subMinutes(10));
        Livewire::test(Settings::class)->assertSee('The queue worker is not running: push messages wait until it starts.');

        $this->beat(now());
        Livewire::test(Settings::class)->assertSee('The queue worker is running.');
    }

    public function test_the_services_card_shows_push_and_the_worker(): void
    {
        $card = strip_tags(Livewire::test(ServicesWidget::class)->html());
        $this->assertStringContainsString('WebDAV-Push', $card);
        $this->assertStringContainsString('Queue worker', $card);
        $this->assertStringContainsString('Not running', $card);

        config(['nexusdav.features.push' => true, 'nexusdav.env_set' => ['NEXUSDAV_PUSH']]);
        $this->beat(now());
        Livewire::test(ServicesWidget::class)
            ->assertSee('Running')
            ->assertSee('Set by environment');
    }

    public function test_needs_attention_warns_when_push_is_on_without_a_running_worker(): void
    {
        $warning = 'WebDAV-Push is on, but the queue worker is not running.';

        // Push off: the worker is optional.
        Livewire::test(AttentionWidget::class)->assertDontSee($warning)->assertSee('Nothing needs attention');

        config(['nexusdav.features.push' => true]);
        Livewire::test(AttentionWidget::class)->assertSee($warning)->assertDontSee('Nothing needs attention');

        $this->beat(now()->subMinutes(6));
        Livewire::test(AttentionWidget::class)->assertSee($warning);

        $this->beat(now());
        Livewire::test(AttentionWidget::class)->assertDontSee($warning)->assertSee('Nothing needs attention');
    }

    public function test_diagnostics_shows_the_worker_and_waiting_jobs(): void
    {
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);

        Livewire::test(QueueWorkerCard::class)
            ->assertSee('Queue worker')
            ->assertSee('No queue worker has run on this server.')
            ->assertSee('Jobs waiting')
            ->assertDontSee('its messages wait in the queue');

        config(['nexusdav.features.push' => true]);
        $this->beat(now()->subHour(), 'a3cab6b1c04d');
        Livewire::test(QueueWorkerCard::class)
            ->assertSee('Not running: last heartbeat 1 hour ago from a3cab6b1c04d.')
            ->assertSee('WebDAV-Push is on: its messages wait in the queue until a worker runs.');

        $this->get(Diagnostics::getUrl())->assertOk()->assertSee('Push subscriptions')->assertSee('Queue worker');
    }

    public function test_the_subscriptions_table_shows_the_host_only_and_revokes(): void
    {
        $bob = app(UserService::class)->create(['username' => 'bob', 'display_name' => 'Bob', 'email' => 'bob@example.com']);
        $ok = $this->subscription($bob, 'calendars/bob/default', 'https://ntfy.example.net/upSecretTopic', ['last_success_at' => now()->subMinute()]);
        $failing = $this->subscription($bob, 'addressbooks/bob/default', 'https://fcm.example.org/wp/abc', [
            'last_success_at' => now()->subDays(2),
            'last_failure_at' => now()->subHour(),
            'last_failure' => '503 Service Unavailable',
        ]);
        $waiting = $this->subscription($bob, 'calendars/bob/', 'https://ntfy.example.net/upOther');

        Livewire::test(PushSubscriptionsTable::class)
            ->assertCanSeeTableRecords([$ok, $failing, $waiting])
            ->assertSee('ntfy.example.net')
            ->assertSee('fcm.example.org')
            ->assertDontSee('upSecretTopic')
            ->assertDontSee('/wp/abc')
            ->assertSee('calendars/bob/default')
            ->assertSee('Delivered')
            ->assertSee('Failing')
            ->assertSee('503 Service Unavailable')
            ->assertSee('No push yet')
            ->assertSee('WebDAV-Push is off: these subscriptions get no messages and expire.')
            ->filterTable('state', 'failing')
            ->assertCanSeeTableRecords([$failing])
            ->assertCanNotSeeTableRecords([$ok, $waiting]);

        Livewire::test(PushSubscriptionsTable::class)
            ->callTableAction('revoke', $failing)
            ->assertNotified('Push subscription revoked');

        $this->assertNull($failing->fresh());
        $this->assertNotNull($ok->fresh());
    }

    public function test_the_worker_uses_the_settings_saved_in_the_panel_for_each_job(): void
    {
        $this->assertFalse(PushConfig::enabled());

        // Saved by the app container: this process's memo and cache are not told.
        Setting::query()->updateOrCreate(['key' => 'push'], ['value' => 'true']);
        Setting::query()->updateOrCreate(['key' => 'push_log_level'], ['value' => '"debug"']);
        $this->assertFalse(PushConfig::enabled());

        event(new JobProcessing('database', new FakeJob));

        $this->assertTrue(PushConfig::enabled());
        $this->assertSame('debug', config('nexusdav.push.log_level'));
    }

    private function beat(CarbonImmutable|\DateTimeInterface $at, string $worker = 'worker-1'): void
    {
        DB::table('worker_heartbeats')->delete();
        DB::table('worker_heartbeats')->insert([
            'worker' => $worker,
            'version' => 'test',
            'started_at' => $at,
            'beat_at' => $at,
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function subscription(User $user, string $path, string $resource, array $values = []): PushSubscription
    {
        return PushSubscription::query()->create($values + [
            'registration' => substr(bin2hex(random_bytes(16)), 0, 32),
            'user_id' => $user->getKey(),
            'scope' => hash('sha256', $path."\n".$resource),
            'path' => $path,
            'topic' => substr(bin2hex(random_bytes(11)), 0, 22),
            'push_resource' => $resource,
            'public_key' => 'BPublicKey',
            'auth_secret' => 'secret',
            'content_update_depth' => '1',
            'expires_at' => now()->addDays(3),
        ]);
    }
}
