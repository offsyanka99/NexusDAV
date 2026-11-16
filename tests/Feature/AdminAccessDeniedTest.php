<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Decision 20 of the Version 2 plan (PR 49): a signed-in user who may not
 * use the admin panel gets an Access denied page and stays signed in.
 */
class AdminAccessDeniedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['nexusdav.env_set' => []]);
    }

    public function test_a_user_who_is_not_an_admin_gets_access_denied_with_a_link_to_the_portal(): void
    {
        app(SettingsService::class)->save(['portal' => true]);
        $this->actingAs(User::factory()->create());

        foreach (['/admin', '/admin/users', '/admin/settings'] as $path) {
            $this->get($path)
                ->assertForbidden()
                ->assertSee('Access denied')
                ->assertSee('The admin panel is for administrators.')
                ->assertSee('User portal')
                ->assertDontSee('Sign out');
        }

        $this->assertAuthenticated();
        $this->get('/')->assertOk();
    }

    public function test_with_the_portal_off_the_page_offers_sign_out_and_it_works(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin')
            ->assertForbidden()
            ->assertSee('Access denied')
            ->assertSee('Sign out')
            ->assertDontSee('User portal');

        $this->post('/admin/logout')->assertRedirect();
        $this->assertGuest();
    }

    public function test_the_page_is_in_the_users_language(): void
    {
        app(SettingsService::class)->save(['portal' => true]);
        $this->actingAs(User::factory()->create(['locale' => 'ru']));

        $this->get('/admin')
            ->assertForbidden()
            ->assertSee('Доступ запрещён')
            ->assertSee('Панель администратора предназначена только для администраторов.')
            ->assertSee('Портал пользователя');
    }

    public function test_an_admin_demoted_while_signed_in_gets_it_on_the_next_request_and_admins_still_get_the_panel(): void
    {
        User::factory()->create(['is_admin' => true]);
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        $this->get('/admin')->assertOk();

        $admin->forceFill(['is_admin' => false])->save();

        $this->get('/admin')->assertForbidden()->assertSee('Access denied');
    }

    public function test_a_livewire_request_from_an_open_admin_page_gets_the_same_403(): void
    {
        User::factory()->create(['is_admin' => true]);
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        $snapshot = $this->snapshotOf($this->get('/admin/settings')->assertOk(), Settings::class);

        $admin->forceFill(['is_admin' => false])->save();

        $this->livewireUpdate($snapshot)->assertForbidden()->assertSee('Access denied');
        $this->assertAuthenticated();
    }

    public function test_the_admin_sign_in_still_refuses_a_user_who_is_not_an_admin(): void
    {
        $this->get('/admin/login')->assertOk();

        $this->actingAs(User::factory()->create());

        // Signed in: Filament sends the sign-in page on to the panel, which refuses.
        $this->get('/admin/login')->assertRedirect('/admin');
    }

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
        Livewire::flushState();

        return $this->withHeaders(['X-Livewire' => '1'])->postJson(Livewire::getUpdateUri(), [
            'components' => [
                ['snapshot' => $snapshot, 'updates' => (object) [], 'calls' => []],
            ],
        ]);
    }
}
