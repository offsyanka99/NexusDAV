<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\UserService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * PR 7: nexusdav:reset-admin-password.
 */
class ResetAdminPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'a-brand-new-password';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_the_password_is_asked_twice_changed_and_never_logged(): void
    {
        $admin = $this->makeUser('root', true);
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        $this->artisan('nexusdav:reset-admin-password', ['--email' => 'ROOT@example.com'])
            ->expectsQuestion('New password (at least 12 characters)', self::NEW_PASSWORD)
            ->expectsQuestion('Repeat the new password', self::NEW_PASSWORD)
            ->expectsOutputToContain('The password of root@example.com was changed.')
            ->doesntExpectOutputToContain(self::NEW_PASSWORD)
            ->assertSuccessful();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, (string) $admin->fresh()?->getAuthPassword()));
        $this->assertNotEmpty(array_filter($logged, fn (string $line): bool => str_contains($line, 'Admin password reset from the command line')));
        $this->assertSame([], array_filter($logged, fn (string $line): bool => str_contains($line, self::NEW_PASSWORD)));
    }

    public function test_a_generated_password_is_printed_once_and_works(): void
    {
        $admin = $this->makeUser('root', true);

        $code = Artisan::call('nexusdav:reset-admin-password', ['--generate' => true, '--no-interaction' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertSame(1, preg_match('/^New password \(shown once.*\):\R(\S+)$/m', $output, $matches), $output);
        $password = $matches[1];
        $this->assertSame(24, strlen($password));
        $this->assertSame(1, substr_count($output, $password));
        $this->assertTrue(Hash::check($password, (string) $admin->fresh()?->getAuthPassword()));
    }

    public function test_existing_sessions_are_signed_out(): void
    {
        $admin = $this->makeUser('root', true);
        $other = $this->makeUser('ada');
        $oldHash = (string) $admin->fresh()?->getAuthPassword();

        $this->actingAs($admin->fresh())
            ->withSession(['password_hash_web' => $oldHash])
            ->get('/admin')
            ->assertOk();

        config(['session.driver' => 'database']);
        foreach ([$admin, $other] as $user) {
            DB::table('sessions')->insert([
                'id' => 'session-'.$user->username,
                'user_id' => $user->getKey(),
                'payload' => base64_encode('a:0:{}'),
                'last_activity' => now()->getTimestamp(),
            ]);
        }

        $this->artisan('nexusdav:reset-admin-password', ['--generate' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $admin->getKey())->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->getKey())->count());

        config(['session.driver' => 'array']);
        $this->actingAs($admin->fresh())
            ->withSession(['password_hash_web' => $oldHash])
            ->get('/admin')
            ->assertRedirect('/admin/login');
    }

    public function test_app_passwords_are_revoked_only_when_asked(): void
    {
        $admin = $this->makeUser('root', true);
        $tokens = app(AppPasswordService::class);
        $tokens->issue($admin, 'phone');
        $tokens->issue($admin, 'laptop');

        $this->artisan('nexusdav:reset-admin-password', ['--generate' => true, '--no-interaction' => true])->assertSuccessful();
        $this->assertSame(2, $admin->appPasswords()->whereNull('revoked_at')->count());

        $this->artisan('nexusdav:reset-admin-password', ['--generate' => true])
            ->expectsConfirmation("Also revoke this admin's 2 app password(s)?", 'no')
            ->assertSuccessful();
        $this->assertSame(2, $admin->appPasswords()->whereNull('revoked_at')->count());

        $this->artisan('nexusdav:reset-admin-password', ['--generate' => true, '--revoke-app-passwords' => true])
            ->expectsOutputToContain('2 app password(s) revoked.')
            ->assertSuccessful();
        $this->assertSame(0, $admin->appPasswords()->whereNull('revoked_at')->count());
    }

    public function test_an_unknown_or_non_admin_email_fails_and_changes_nothing(): void
    {
        $admin = $this->makeUser('root', true);
        $this->makeUser('ada');
        $before = (string) $admin->fresh()?->getAuthPassword();

        $this->artisan('nexusdav:reset-admin-password', ['--email' => 'nobody@example.com', '--generate' => true])
            ->expectsOutputToContain('No admin account has that email.')
            ->assertFailed();

        $this->artisan('nexusdav:reset-admin-password', ['--email' => 'ada@example.com', '--generate' => true])
            ->expectsOutputToContain('No admin account has that email.')
            ->assertFailed();

        $this->assertSame($before, (string) $admin->fresh()?->getAuthPassword());
    }

    public function test_short_mismatched_or_missing_passwords_change_nothing(): void
    {
        $admin = $this->makeUser('root', true);
        $before = (string) $admin->fresh()?->getAuthPassword();

        $this->artisan('nexusdav:reset-admin-password')
            ->expectsQuestion('New password (at least 12 characters)', 'too-short')
            ->expectsOutputToContain('at least 12 characters')
            ->assertFailed();

        $this->artisan('nexusdav:reset-admin-password')
            ->expectsQuestion('New password (at least 12 characters)', self::NEW_PASSWORD)
            ->expectsQuestion('Repeat the new password', 'something-else-entirely')
            ->expectsOutputToContain('The passwords do not match.')
            ->assertFailed();

        $this->artisan('nexusdav:reset-admin-password', ['--no-interaction' => true])
            ->expectsOutputToContain('use --generate')
            ->assertFailed();

        $this->assertSame($before, (string) $admin->fresh()?->getAuthPassword());
    }

    public function test_more_than_one_admin_needs_an_email(): void
    {
        $this->makeUser('root', true);
        $second = $this->makeUser('second', true);

        $this->artisan('nexusdav:reset-admin-password', ['--generate' => true])
            ->expectsOutputToContain('There is more than one admin.')
            ->assertFailed();

        $this->artisan('nexusdav:reset-admin-password', ['--email' => 'second@example.com', '--generate' => true])
            ->assertSuccessful();
        $this->assertNotSame('correct-horse-battery', $second->fresh()?->getAuthPassword());
        $this->assertFalse(Hash::check('correct-horse-battery', (string) $second->fresh()?->getAuthPassword()));
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
}
