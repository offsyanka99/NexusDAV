<?php

namespace Tests\Feature;

use App\Filament\Auth\Login;
use App\Filament\Auth\PortalLogin;
use App\Filament\Widgets\Diagnostics\FailedLoginsTable;
use App\Models\DiagnosticEvent;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\DiagnosticReason;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PR 48 of the Version 2 plan: the portal sign-in has the same limits as the
 * admin sign-in under the source portal, and its failures are on
 * Diagnostics with their reason.
 */
class PortalLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['nexusdav.env_set' => []]);
        app(SettingsService::class)->save(['portal' => true]);
        Filament::setCurrentPanel(Filament::getPanel('portal'));
    }

    public function test_each_refusal_is_stored_with_the_portal_source_and_its_reason(): void
    {
        User::factory()->create(['username' => 'ada', 'email' => 'ada@example.com', 'password' => 'secret-password']);
        User::factory()->create(['username' => 'beau', 'password' => 'secret-password', 'is_active' => false]);
        User::factory()->create(['username' => 'cleo', 'password' => null]);

        $this->failLogin('nobody', 'whatever-password');
        $this->failLogin('ada@example.com', 'wrong-password');
        $this->failLogin('beau', 'secret-password');
        $this->failLogin('cleo', 'any-password');

        $rows = DiagnosticEvent::query()->orderBy('id')->get();

        $this->assertSame(
            [
                ['nobody', 'unknown user'],
                ['ada', 'bad password'],
                ['beau', 'user disabled'],
                ['cleo', 'no password'],
            ],
            $rows->map(fn (DiagnosticEvent $row): array => [$row->username, $row->reason])->all(),
        );

        foreach ($rows as $row) {
            $this->assertSame(DiagnosticEvent::SOURCE_PORTAL, $row->source);
            $this->assertSame(DiagnosticEvent::KIND_AUTH_FAILED, $row->kind);
            $this->assertSame('/login', $row->path);
            $this->assertSame(422, $row->status);
        }

        $encoded = (string) json_encode($rows->toArray());
        $this->assertStringNotContainsString('password', str_replace(['bad password', 'no password'], '', $encoded));
    }

    public function test_the_admin_sign_in_keeps_its_own_source_and_reasons(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        User::factory()->create(['username' => 'beau', 'email' => 'beau@example.com', 'password' => 'secret-password', 'is_active' => false, 'is_admin' => true]);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'beau@example.com', 'password' => 'secret-password'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $row = DiagnosticEvent::query()->sole();
        $this->assertSame(DiagnosticEvent::SOURCE_ADMIN, $row->source);
        $this->assertSame('panel refused', $row->reason);
        $this->assertSame('/admin/login', $row->path);
    }

    public function test_the_portal_sign_in_is_throttled_per_account_and_address(): void
    {
        config(['nexusdav.rate_limit.max_attempts' => 3]);
        $ada = User::factory()->create(['username' => 'ada', 'email' => 'ada@example.com', 'password' => 'secret-password']);

        $component = Livewire::test(PortalLogin::class);

        foreach (['ada', 'ada@example.com', 'ADA'] as $attempt => $login) {
            $component
                ->fillForm(['email' => $login, 'password' => 'wrong-password-'.$attempt])
                ->call('authenticate')
                ->assertHasFormErrors(['email']);
        }

        // The email and the username share one bucket.
        foreach (['ada', 'ada@example.com'] as $login) {
            Livewire::test(PortalLogin::class)
                ->fillForm(['email' => $login, 'password' => 'secret-password'])
                ->call('authenticate')
                ->assertNotified('Too many login attempts');

            $this->assertGuest();
        }

        // The portal and the admin sign-in count separately per account.
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $ada->forceFill(['is_admin' => true])->save();

        Livewire::test(Login::class)
            ->fillForm(['email' => 'ada@example.com', 'password' => 'secret-password'])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertAuthenticatedAs($ada);
    }

    public function test_diagnostics_show_the_portal_source_and_reasons_in_the_panel_language(): void
    {
        DiagnosticEvent::query()->create([
            'occurred_at' => now(),
            'source' => DiagnosticEvent::SOURCE_PORTAL,
            'kind' => DiagnosticEvent::KIND_AUTH_FAILED,
            'ip' => '127.0.0.1',
            'username' => 'cleo',
            'method' => 'POST',
            'path' => '/login',
            'status' => 422,
            'reason' => 'no password',
            'repeat_count' => 1,
            'bucket' => intdiv(now()->getTimestamp(), 60),
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(FailedLoginsTable::class)
            ->assertSee('User portal')
            ->assertSee('no password');

        App::setLocale('ru');
        $this->assertSame('нет пароля', DiagnosticReason::label('no password'));
        $this->assertSame('пользователь отключён', DiagnosticReason::label('user disabled'));

        Livewire::test(FailedLoginsTable::class)
            ->assertSee('Портал пользователя')
            ->assertSee('нет пароля');
    }

    private function failLogin(string $login, string $password): void
    {
        Livewire::test(PortalLogin::class)
            ->fillForm(['email' => $login, 'password' => $password])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
    }
}
