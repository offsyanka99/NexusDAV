<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use ReflectionProperty;
use Tests\TestCase;

/**
 * PR 48 of the Version 2 plan, Decision 3: one sign-in and one session for
 * both panels, and an idle lifetime per panel.
 */
class PanelSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['nexusdav.env_set' => []]);
        app(SettingsService::class)->save(['portal' => true, 'session_lifetime' => 120, 'portal_session_lifetime' => 480]);
    }

    public function test_signing_out_of_the_portal_signs_out_of_the_admin_panel(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $this->get('/')->assertOk();
        $this->get('/admin')->assertOk();

        $this->post('/logout')->assertRedirect();

        $this->assertGuest();
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/')->assertRedirect('/login');
    }

    public function test_signing_out_of_the_admin_panel_signs_out_of_the_portal(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $this->get('/')->assertOk();
        $this->post('/admin/logout')->assertRedirect();

        $this->assertGuest();
        $this->get('/')->assertRedirect('/login');
    }

    public function test_portal_activity_does_not_keep_an_idle_admin_panel_signed_in(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $this->get('/admin')->assertOk();
        $this->get('/')->assertOk();

        $this->travel(100)->minutes();
        $this->get('/')->assertOk();

        $this->travel(19)->minutes();
        $this->get('/')->assertOk();
        $this->assertAuthenticated();

        $this->travel(1)->minutes();
        $this->get('/admin')->assertRedirect($this->loginUrl('admin'));

        $this->assertGuest();
        $this->get('/')->assertRedirect('/login');
    }

    public function test_admin_activity_does_not_keep_an_idle_portal_signed_in(): void
    {
        app(SettingsService::class)->save(['portal_session_lifetime' => 30]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $this->get('/')->assertOk();

        foreach (range(1, 3) as $step) {
            $this->travel(10)->minutes();
            $this->get('/admin')->assertOk();
        }

        $this->get('/')->assertRedirect($this->loginUrl('portal'));

        $this->assertGuest();
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_activity_in_a_panel_keeps_that_panel_signed_in(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        foreach (range(1, 4) as $step) {
            $this->get('/admin')->assertOk();
            $this->travel(100)->minutes();
        }

        $this->get('/admin')->assertOk();
        $this->assertAuthenticated();
    }

    public function test_a_new_sign_in_starts_the_idle_time_of_every_panel_again(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get('/admin')->assertOk();

        $this->travel(200)->minutes();
        // A sign-in at the portal (the Login event) forgets the old admin time.
        event(new Login('web', auth()->user(), false));

        $this->get('/admin')->assertOk();
        $this->assertAuthenticated();
    }

    public function test_a_livewire_action_after_the_lifetime_ends_the_session(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $snapshot = $this->snapshotOf($this->get('/admin/settings')->assertOk(), Settings::class);

        $this->travel(60)->minutes();
        $this->livewireUpdate($snapshot)->assertOk();

        $this->travel(119)->minutes();
        $this->get('/')->assertOk();
        $this->assertAuthenticated();

        $this->travel(2)->minutes();
        $this->livewireUpdate($snapshot)->assertUnauthorized();

        $this->assertGuest();
        $this->get('/')->assertRedirect('/login');
    }

    public function test_the_session_lives_for_the_longer_lifetime_while_the_portal_is_on(): void
    {
        $this->assertSame(480, config('session.lifetime'));
        $this->assertSessionCookieLasts(480, $this->get('/login'));
        $this->assertSessionCookieLasts(480, $this->get('/admin/login'));
        $this->assertSame(480, $this->databaseSessionMinutes());

        app(SettingsService::class)->save(['session_lifetime' => 600, 'portal_session_lifetime' => 30]);
        $this->assertSame(600, config('session.lifetime'));

        app(SettingsService::class)->save(['portal' => false, 'session_lifetime' => 120, 'portal_session_lifetime' => 480]);
        $this->assertSame(120, config('session.lifetime'));
        $this->assertSessionCookieLasts(120, $this->get('/admin/login'));
    }

    private function loginUrl(string $panel): string
    {
        return ($panel === 'admin' ? '/admin' : '').'/login?timeout=1';
    }

    private function assertSessionCookieLasts(int $minutes, TestResponse $response): void
    {
        $cookie = $response->getCookie((string) config('session.cookie'), false);

        $this->assertNotNull($cookie);
        $this->assertEqualsWithDelta(now()->addMinutes($minutes)->getTimestamp(), $cookie->getExpiresTime(), 5);
    }

    private function databaseSessionMinutes(): int
    {
        $handler = app('session')->driver('database')->getHandler();
        $this->assertInstanceOf(DatabaseSessionHandler::class, $handler);

        return (int) (new ReflectionProperty($handler, 'minutes'))->getValue($handler);
    }

    /**
     * @return string The component's snapshot as the page sent it.
     */
    private function snapshotOf(TestResponse $response, string $component): string
    {
        preg_match_all('/wire:snapshot="([^"]+)"/', (string) $response->getContent(), $matches);

        foreach ($matches[1] as $encoded) {
            $snapshot = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5);
            $decoded = json_decode($snapshot, true);

            if (is_array($decoded) && ($decoded['memo']['name'] ?? null) === $component) {
                return $snapshot;
            }
        }

        $this->fail('No snapshot of '.$component.' on the page.');
    }

    private function livewireUpdate(string $snapshot): TestResponse
    {
        // A real request starts with fresh Livewire state; the test app is reused.
        Livewire::flushState();

        return $this->withHeaders(['X-Livewire' => '1'])->postJson(Livewire::getUpdateUri(), [
            'components' => [
                ['snapshot' => $snapshot, 'updates' => (object) [], 'calls' => []],
            ],
        ]);
    }
}
