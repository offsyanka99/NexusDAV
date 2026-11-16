<?php

namespace Tests\Feature;

use App\Filament\Auth\PortalLogin;
use App\Filament\Pages\Configuration;
use App\Filament\Pages\Settings;
use App\Models\User;
use App\Providers\Filament\PortalPanelProvider;
use App\Services\AppPasswordService;
use App\Services\Portal\PortalLog;
use App\Services\SettingsService;
use App\Services\SettingsTransfer;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route as RouteFacade;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PR 48 of the Version 2 plan: the portal panel at /, the Portal switch,
 * who may use the portal, its sign-in, the links between the panels, the
 * reserved slugs and the portal settings.
 */
class PortalPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['nexusdav.env_set' => []]);
    }

    public function test_switch_off_redirects_every_portal_url_to_the_admin_panel(): void
    {
        $this->assertFalse(app(SettingsService::class)->get('portal'));

        foreach ($this->portalGetUris() as $uri) {
            $this->get($uri)->assertRedirect('/admin');
        }

        $this->post('/logout')->assertRedirect('/admin');

        $this->actingAs(User::factory()->create());
        $this->get('/')->assertRedirect('/admin');
        $this->get('/login')->assertRedirect('/admin');
    }

    public function test_switch_off_leaves_the_admin_panel_as_in_1_6_0(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk()->assertSee('Email');

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        $this->get('/admin')->assertOk()->assertDontSee('User portal');
        $this->assertSame(120, config('session.lifetime'));
    }

    public function test_switch_off_refuses_the_portal_sign_in(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('portal'));
        $user = User::factory()->create(['email' => 'ada@example.com', 'password' => 'secret-password']);

        Livewire::test(PortalLogin::class)
            ->fillForm(['email' => 'ada@example.com', 'password' => 'secret-password'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
        $this->assertFalse($user->canAccessPanel(Filament::getPanel('portal')));
    }

    public function test_switch_on_opens_the_portal_sign_in_at_the_root(): void
    {
        $this->enablePortal();

        $this->get('/')->assertRedirect('/login');
        $this->get('/login')
            ->assertOk()
            ->assertSee('Email or username')
            ->assertSee('Password')
            ->assertDontSee('Remember me');
    }

    public function test_a_user_signs_in_by_email_or_username_and_sees_the_dashboard(): void
    {
        $this->enablePortal();
        Filament::setCurrentPanel(Filament::getPanel('portal'));
        $user = User::factory()->create([
            'username' => 'ada',
            'display_name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'secret-password',
        ]);

        Livewire::test(PortalLogin::class)
            ->fillForm(['email' => ' Ada@Example.com ', 'password' => 'secret-password'])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect();
        $this->assertAuthenticatedAs($user);

        auth()->logout();

        Livewire::test(PortalLogin::class)
            ->fillForm(['email' => 'ADA', 'password' => 'secret-password'])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect();
        $this->assertAuthenticatedAs($user);

        $this->get('/')->assertOk()->assertSee('Welcome, Ada Lovelace.');
    }

    public function test_an_app_password_does_not_sign_in_to_the_portal(): void
    {
        $this->enablePortal();
        Filament::setCurrentPanel(Filament::getPanel('portal'));
        $user = User::factory()->create(['username' => 'ada', 'password' => 'secret-password']);
        $token = app(AppPasswordService::class)->issue($user, 'Phone')['token'];

        Livewire::test(PortalLogin::class)
            ->fillForm(['email' => 'ada', 'password' => $token])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
    }

    public function test_can_access_panel_for_every_case(): void
    {
        $admin = Filament::getPanel('admin');
        $portal = Filament::getPanel('portal');

        $activeAdmin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $disabled = User::factory()->create(['is_active' => false]);
        $noPassword = User::factory()->create(['password' => null]);

        config(['nexusdav.portal.enabled' => false]);
        $this->assertTrue($activeAdmin->canAccessPanel($admin));
        $this->assertFalse($activeAdmin->canAccessPanel($portal));
        $this->assertFalse($user->canAccessPanel($portal));

        config(['nexusdav.portal.enabled' => true]);
        $this->assertTrue($activeAdmin->canAccessPanel($admin));
        $this->assertTrue($activeAdmin->canAccessPanel($portal));
        $this->assertFalse($user->canAccessPanel($admin));
        $this->assertTrue($user->canAccessPanel($portal));
        $this->assertFalse($disabled->canAccessPanel($portal));
        $this->assertFalse($disabled->canAccessPanel($admin));
        $this->assertFalse($noPassword->canAccessPanel($portal));
        $this->assertFalse($noPassword->hasPassword());
        $this->assertTrue($user->hasPassword());
    }

    public function test_the_panels_link_to_each_other(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();

        $this->actingAs($admin);
        $this->get('/admin')->assertOk()->assertDontSee('User portal');

        $this->enablePortal();
        $this->get('/admin')->assertOk()->assertSee('User portal');
        $this->get('/')->assertOk()->assertSee('Admin panel');

        $this->actingAs($user);
        $this->get('/')->assertOk()->assertDontSee('Admin panel');
    }

    public function test_portal_slugs_never_take_a_server_path(): void
    {
        $this->assertSame('admin', Filament::getDefaultPanel()->getId());
        $this->assertSame('', Filament::getPanel('portal')->getPath());

        $portalRoutes = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => str_starts_with((string) $route->getName(), 'filament.portal.'));

        $this->assertNotEmpty($portalRoutes);

        foreach ($portalRoutes as $route) {
            $segment = explode('/', trim($route->uri(), '/'))[0];

            foreach (PortalPanelProvider::RESERVED_SLUGS as $reserved) {
                $this->assertFalse(fnmatch($reserved, $segment), 'The portal route '.$route->uri().' takes the reserved path '.$reserved.'.');
            }
        }

        $this->enablePortal();

        $this->get('/health')->assertOk();
        $this->get('/up')->assertOk();
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->call('PROPFIND', '/.well-known/caldav')->assertStatus(301);
        $this->call('PROPFIND', '/dav/')->assertUnauthorized();
    }

    public function test_the_portal_settings_are_on_their_pages_and_in_the_export(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(Settings::class)
            ->assertFormFieldExists('portal')
            ->fillForm(['portal' => true, 'portal_log_level' => 'debug'])
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(Configuration::class)
            ->assertFormFieldExists('portal_session_lifetime')
            ->fillForm(['portal_session_lifetime' => 4])
            ->call('save')
            ->assertHasFormErrors(['portal_session_lifetime' => 'min'])
            ->fillForm(['portal_session_lifetime' => 600])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(config('nexusdav.portal.enabled'));
        $this->assertSame('debug', config('nexusdav.portal.log_level'));
        $this->assertSame(600, config('nexusdav.portal.session_lifetime'));

        $transfer = app(SettingsTransfer::class);
        $settings = $transfer->export()['settings'];

        $this->assertTrue($settings['portal']);
        $this->assertSame('debug', $settings['portal_log_level']);
        $this->assertSame(600, $settings['portal_session_lifetime']);
        $this->assertSame('User portal', $transfer->label('portal'));
        $this->assertSame('Portal log level', $transfer->label('portal_log_level'));
        $this->assertSame('Portal session lifetime', $transfer->label('portal_session_lifetime'));
        $this->assertSame('Debug', $transfer->display('portal_log_level', 'debug'));
    }

    public function test_settings_set_by_the_environment_are_locked(): void
    {
        config(['nexusdav.env_set' => ['NEXUSDAV_PORTAL', 'NEXUSDAV_PORTAL_LOG_LEVEL', 'NEXUSDAV_PORTAL_SESSION_LIFETIME']]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(Settings::class)->assertFormFieldIsDisabled('portal');
        Livewire::test(Configuration::class)->assertFormFieldIsDisabled('portal_session_lifetime');

        app(SettingsService::class)->save(['portal' => true, 'portal_session_lifetime' => 600]);

        $this->assertFalse(app(SettingsService::class)->isSaved('portal'));
        $this->assertSame([], array_intersect(
            ['portal', 'portal_log_level', 'portal_session_lifetime'],
            array_keys(app(SettingsTransfer::class)->export()['settings']),
        ));
    }

    public function test_the_user_page_says_whether_the_user_can_sign_in_to_the_portal(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $user = User::factory()->create();
        $disabled = User::factory()->create(['is_active' => false]);
        $noPassword = User::factory()->create(['password' => null]);

        $this->get('/admin/users/'.$user->getKey().'/edit')
            ->assertOk()
            ->assertSee('Portal sign-in')
            ->assertSee('User portal is off')
            ->assertSee('never to DAV');

        $this->enablePortal();

        $this->get('/admin/users/'.$user->getKey().'/edit')->assertOk()->assertSee('Possible');
        $this->get('/admin/users/'.$disabled->getKey().'/edit')->assertOk()->assertSee('User disabled');
        $this->get('/admin/users/'.$noPassword->getKey().'/edit')->assertOk()->assertSee('No password');
    }

    public function test_portal_log_lines_follow_the_portal_log_level(): void
    {
        $lines = [];
        Log::listen(function (MessageLogged $event) use (&$lines): void {
            $lines[] = [$event->level, $event->message, $event->context];
        });

        config(['nexusdav.portal.log_level' => 'warning']);
        PortalLog::info('Portal sign-in', ['username' => 'ada']);
        $this->assertSame([], $lines);

        config(['nexusdav.portal.log_level' => 'info']);
        PortalLog::info('Portal sign-in', ['username' => 'ada']);
        $this->assertSame([['info', 'Portal sign-in', ['username' => 'ada']]], $lines);
    }

    public function test_a_portal_sign_in_is_logged_without_the_password(): void
    {
        $this->enablePortal();
        Filament::setCurrentPanel(Filament::getPanel('portal'));
        User::factory()->create(['username' => 'ada', 'password' => 'secret-password']);
        $lines = [];
        Log::listen(function (MessageLogged $event) use (&$lines): void {
            $lines[] = [$event->level, $event->message, $event->context];
        });

        Livewire::test(PortalLogin::class)
            ->fillForm(['email' => 'ada', 'password' => 'secret-password'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertContains(['info', 'Portal sign-in', ['username' => 'ada']], $lines);
        $this->assertStringNotContainsString('secret-password', (string) json_encode($lines));
    }

    private function enablePortal(): void
    {
        app(SettingsService::class)->save(['portal' => true]);
    }

    /**
     * @return list<string>
     */
    private function portalGetUris(): array
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => str_starts_with((string) $route->getName(), 'filament.portal.') && in_array('GET', $route->methods(), true))
            ->map(fn (Route $route): string => '/'.ltrim($route->uri(), '/'))
            ->values()
            ->all();
    }
}
