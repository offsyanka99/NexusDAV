<?php

namespace Tests\Feature;

use App\Filament\Auth\Login;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_active_admin_can_log_in(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'secret-password',
            'is_admin' => true,
            'is_active' => true,
        ]);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => 'admin@example.com',
                'password' => 'secret-password',
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertAuthenticatedAs($admin);
    }

    public function test_disabled_admin_cannot_log_in(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'secret-password',
            'is_admin' => true,
            'is_active' => false,
        ]);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => 'admin@example.com',
                'password' => 'secret-password',
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
    }

    public function test_remember_me_login_does_not_fail_without_a_remember_token_column(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'secret-password',
            'is_admin' => true,
            'is_active' => true,
        ]);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => 'admin@example.com',
                'password' => 'secret-password',
                'remember' => true,
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertAuthenticatedAs($admin);

        auth()->login($admin, true);

        $this->assertAuthenticatedAs($admin);
        $this->assertNull($admin->fresh()?->getAttributes()['remember_token'] ?? null);
    }

    public function test_filament_name_uses_display_name(): void
    {
        $user = User::factory()->create([
            'display_name' => 'Ada Lovelace',
            'username' => 'ada',
            'email' => 'ada@example.com',
        ]);

        $this->assertSame('Ada Lovelace', $user->getFilamentName());

        $user->display_name = '';

        $this->assertSame('ada', $user->getFilamentName());

        $user->username = '';

        $this->assertSame('ada@example.com', $user->getFilamentName());
    }
}
