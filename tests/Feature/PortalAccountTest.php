<?php

namespace Tests\Feature;

use App\Filament\Portal\Account;
use App\Filament\Portal\AppPasswords;
use App\Models\AppPassword;
use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\SettingsService;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PR 49 of the Version 2 plan: the Account page (time zone, week numbers,
 * password) and the user's own app passwords.
 */
class PortalAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $ada;

    protected function setUp(): void
    {
        parent::setUp();

        config(['nexusdav.env_set' => []]);
        app(SettingsService::class)->save(['portal' => true]);
        Filament::setCurrentPanel(Filament::getPanel('portal'));

        $this->ada = User::factory()->create(['username' => 'ada', 'password' => 'old-password-1234']);
        $this->actingAs($this->ada);
    }

    public function test_the_user_menu_opens_the_account_and_app_passwords_pages(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('/account', false)
            ->assertSee('/app-passwords', false)
            ->assertSee('Account')
            ->assertSee('App passwords');

        $this->get('/account')->assertOk()->assertSee('Time zone')->assertSee('Show week numbers')->assertSee('Change password');
        $this->get('/app-passwords')->assertOk()->assertSee('Generate app password');
    }

    public function test_the_time_zone_and_week_numbers_are_saved_and_used(): void
    {
        Livewire::test(Account::class)
            ->assertFormSet(['timezone' => null, 'show_week_numbers' => false])
            ->fillForm(['timezone' => 'Asia/Tokyo', 'show_week_numbers' => true])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Saved');

        $this->ada->refresh();
        $this->assertSame('Asia/Tokyo', $this->ada->timezone);
        $this->assertTrue($this->ada->show_week_numbers);

        Livewire::test(Account::class)
            ->fillForm(['timezone' => 'Not/AZone'])
            ->call('save')
            ->assertHasFormErrors(['timezone']);

        $this->assertSame('Asia/Tokyo', $this->ada->fresh()?->timezone);

        // Dates in the portal are in the user's zone: 00:00 UTC is 09:00 in Tokyo.
        $this->travelTo(Carbon::parse('2026-01-01 00:00:00', 'UTC'));
        app(AppPasswordService::class)->issue($this->ada, 'Phone');

        $this->get('/app-passwords')->assertOk()->assertSee('Jan 1, 2026 09:00:00');

        Livewire::test(Account::class)
            ->fillForm(['timezone' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        config(['nexusdav.default_timezone' => 'UTC']);
        $this->assertNull($this->ada->fresh()?->timezone);
        $this->get('/app-passwords')->assertOk()->assertSee('Jan 1, 2026 00:00:00');
    }

    public function test_the_password_change_needs_the_current_password_and_a_long_new_one(): void
    {
        Livewire::test(Account::class)
            ->callAction('changePassword', data: [
                'current_password' => 'wrong-password-1',
                'password' => 'new-password-5678',
                'password_confirmation' => 'new-password-5678',
            ])
            ->assertHasActionErrors(['current_password']);

        Livewire::test(Account::class)
            ->callAction('changePassword', data: [
                'current_password' => 'old-password-1234',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertHasActionErrors(['password' => 'min']);

        Livewire::test(Account::class)
            ->callAction('changePassword', data: [
                'current_password' => 'old-password-1234',
                'password' => 'new-password-5678',
                'password_confirmation' => 'other-password-5678',
            ])
            ->assertHasActionErrors(['password']);

        $this->assertTrue(Hash::check('old-password-1234', $this->ada->fresh()?->getAuthPassword() ?? ''));
    }

    public function test_wrong_current_passwords_are_throttled(): void
    {
        config(['nexusdav.rate_limit.max_attempts' => 2]);

        foreach (['wrong-password-1', 'wrong-password-2', 'old-password-1234'] as $current) {
            Livewire::test(Account::class)
                ->callAction('changePassword', data: [
                    'current_password' => $current,
                    'password' => 'new-password-5678',
                    'password_confirmation' => 'new-password-5678',
                ])
                ->assertHasActionErrors(['current_password']);
        }

        $this->assertTrue(Hash::check('old-password-1234', $this->ada->fresh()?->getAuthPassword() ?? ''));
    }

    public function test_changing_the_password_keeps_this_session_and_ends_the_others(): void
    {
        config(['session.driver' => 'database']);
        $other = User::factory()->create();

        foreach ([['own-other', $this->ada->getKey()], ['someone-else', $other->getKey()], [session()->getId(), $this->ada->getKey()]] as [$id, $userId]) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => '', 'last_activity' => time()]);
        }

        $lines = [];
        Log::listen(function (MessageLogged $event) use (&$lines): void {
            $lines[] = [$event->message, $event->context];
        });

        Livewire::test(Account::class)
            ->callAction('changePassword', data: [
                'current_password' => 'old-password-1234',
                'password' => 'new-password-5678',
                'password_confirmation' => 'new-password-5678',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Password changed');

        $fresh = $this->ada->fresh();
        $this->assertTrue(Hash::check('new-password-5678', $fresh?->getAuthPassword() ?? ''));
        $remaining = DB::table('sessions')->pluck('id')->all();
        $this->assertNotContains('own-other', $remaining);
        $this->assertContains('someone-else', $remaining);
        $this->assertContains(session()->getId(), $remaining);
        $this->assertSame(
            auth()->guard('web')->hashPasswordForCookie((string) $fresh?->getAuthPassword()),
            session('password_hash_web'),
        );

        $this->assertContains(['Portal password changed', ['username' => 'ada']], $lines);
        $this->assertStringNotContainsString('new-password-5678', (string) json_encode($lines));
    }

    public function test_only_the_users_own_app_passwords_are_listed_and_revoked(): void
    {
        $bob = User::factory()->create(['username' => 'bob']);
        $own = app(AppPasswordService::class)->issue($this->ada, 'Ada phone')['app_password'];
        $foreign = app(AppPasswordService::class)->issue($bob, 'Bob phone')['app_password'];

        Livewire::test(AppPasswords::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign])
            ->callTableAction('revoke', $own)
            ->assertNotified('App password revoked');

        $this->assertNotNull($own->fresh()?->revoked_at);

        // An id of another user's password is not found in this table.
        try {
            Livewire::test(AppPasswords::class)->callTableAction('revoke', $foreign);
            $this->fail('Another user\'s app password was found.');
        } catch (ActionNotResolvableException $exception) {
            $this->assertStringContainsString('no longer exists', $exception->getMessage());
        }

        $this->assertNull($foreign->fresh()?->revoked_at);
    }

    public function test_a_generated_app_password_is_shown_once_and_works_on_dav_until_revoked(): void
    {
        $component = Livewire::test(AppPasswords::class)
            ->callTableAction('generate', data: ['name' => 'Laptop'])
            ->assertHasNoTableActionErrors()
            ->assertNotified('App password created');

        $token = $component->get('plainTextToken');
        $this->assertIsString($token);
        $component->assertSee($token);

        $password = AppPassword::query()->where('user_id', $this->ada->getKey())->sole();
        $this->assertSame('Laptop', $password->name);
        $this->assertStringNotContainsString(explode('.', $token)[1] ?? $token, (string) json_encode($password->getAttributes()));

        $this->dav($token)->assertStatus(207);

        Livewire::test(AppPasswords::class)
            ->assertDontSee($token)
            ->callTableAction('revoke', $password);

        $this->dav($token)->assertUnauthorized();
    }

    private function dav(string $token): TestResponse
    {
        return $this->call('PROPFIND', '/dav/', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Basic '.base64_encode('ada:'.$token),
            'HTTP_DEPTH' => '0',
        ]);
    }
}
