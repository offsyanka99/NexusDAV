<?php

namespace Tests\Feature;

use App\Filament\Auth\Login;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * An idle admin is sent back to the sign-in page: a script on every
 * signed-in panel page counts the session lifetime, and the sign-in page
 * explains why with a message that stays until it is closed.
 */
class SessionTimeoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_signed_in_pages_carry_the_idle_script_with_the_session_lifetime(): void
    {
        config(['session.lifetime' => 7]);
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_active' => true]));

        $this->get('/admin')
            ->assertOk()
            ->assertSee('nexusdav.panel.activity', false)
            ->assertSee('const lifetimeMs = 7 * 60 * 1000;', false)
            ->assertSee('admin\/login?timeout=1', false)
            ->assertSee('admin\/logout', false);
    }

    public function test_the_sign_in_page_does_not_carry_the_idle_script(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertDontSee('nexusdav.panel.activity', false);
    }

    public function test_the_sign_in_page_explains_a_timeout_and_the_message_stays(): void
    {
        Livewire::withQueryParams(['timeout' => 1])
            ->test(Login::class)
            ->assertNotified(
                Notification::make()
                    ->warning()
                    ->title('Session ended')
                    ->body('You were signed out because of inactivity. Sign in again to continue.')
                    ->persistent(),
            );

        $this->assertSame('persistent', Notification::make()->warning()->getDuration());
    }

    public function test_the_sign_in_page_says_nothing_without_a_timeout(): void
    {
        Livewire::test(Login::class)->assertNotNotified();
    }
}
