<?php

namespace Tests\Feature;

use App\Models\Principal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Tests\TestCase;

class FirstAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_admin_creation_is_idempotent(): void
    {
        $this->setEnv('APP_URL', 'http://localhost:8080');
        $this->setEnv('ADMIN_EMAIL', 'Admin.User+tag@example.com');
        $this->setEnv('ADMIN_PASSWORD', 'correct-horse-battery');

        $this->artisan('nexusdav:prepare')->assertSuccessful();

        $admin = User::query()->sole();
        $before = $admin->getAttributes();

        $this->assertSame('adminusertag', $admin->username);
        $this->assertSame('Admin.User+tag', $admin->display_name);
        $this->assertSame('Admin.User+tag@example.com', $admin->email);
        $this->assertTrue($admin->is_admin);
        $this->assertTrue($admin->is_active);
        $this->assertTrue(Hash::check('correct-horse-battery', (string) $admin->password));
        $this->assertStringStartsWith('$argon2id$', (string) $admin->password);
        $this->assertNull($admin->getRawOriginal('timezone'));
        $this->assertSame('UTC', $admin->resolvedTimezone());
        $this->assertSame('en', $admin->resolvedLocale());
        $this->assertSame('principals/'.$admin->username, $admin->principal?->uri);
        $this->assertSame($admin->display_name, $admin->principal?->display_name);
        $this->assertSame($admin->email, $admin->principal?->email);

        $this->setEnv('ADMIN_EMAIL', 'other@example.com');
        $this->setEnv('ADMIN_PASSWORD', 'different-password');

        $this->artisan('nexusdav:prepare')->assertSuccessful();

        $after = User::query()->sole()->getAttributes();

        $this->assertSame($before, $after);
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, Principal::query()->count());
        $this->assertSame('principals/adminusertag', Principal::query()->sole()->uri);
    }

    public function test_prepare_adds_a_principal_without_changing_an_existing_admin(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
            'email' => 'admin@example.com',
        ]);
        $before = $admin->fresh()?->getAttributes();

        $this->setEnv('APP_URL', 'http://localhost:8080');
        $this->setEnv('ADMIN_EMAIL', 'other@example.com');
        $this->setEnv('ADMIN_PASSWORD', 'different-password');

        $this->artisan('nexusdav:prepare')->assertSuccessful();

        $fresh = User::query()->sole();

        $this->assertSame($before, $fresh->getAttributes());
        $this->assertSame('principals/'.$admin->username, $fresh->principal?->uri);
        $this->assertSame($fresh->display_name, $fresh->principal?->display_name);
        $this->assertSame($fresh->email, $fresh->principal?->email);
    }

    public function test_prepare_stops_when_a_required_variable_is_missing(): void
    {
        $this->setEnv('APP_URL', null);

        $this->artisan('nexusdav:prepare')
            ->expectsOutputToContain('APP_URL')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_prepare_stops_when_the_admin_username_cannot_be_derived(): void
    {
        $this->setEnv('APP_URL', 'http://localhost:8080');
        $this->setEnv('ADMIN_EMAIL', '+@example.com');
        $this->setEnv('ADMIN_PASSWORD', 'correct-horse-battery');

        $this->artisan('nexusdav:prepare')
            ->expectsOutputToContain('Cannot derive a valid username from ADMIN_EMAIL')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_prepare_does_not_log_the_password_when_the_insert_conflicts(): void
    {
        User::factory()->create([
            'username' => 'someone',
            'email' => 'admin@example.com',
            'is_admin' => false,
        ]);

        $this->setEnv('APP_URL', 'http://localhost:8080');
        $this->setEnv('ADMIN_EMAIL', 'admin@example.com');
        $this->setEnv('ADMIN_PASSWORD', 'correct-horse-battery');

        $this->artisan('nexusdav:prepare')
            ->expectsOutputToContain('constraint')
            ->doesntExpectOutputToContain('correct-horse-battery')
            ->doesntExpectOutputToContain('$argon2id$')
            ->assertFailed();
    }

    public function test_username_is_immutable_after_create(): void
    {
        $user = User::factory()->create(['username' => 'admin']);

        $user->update([
            'username' => 'renamed',
            'display_name' => 'Changed',
        ]);

        $fresh = $user->fresh();

        $this->assertNotNull($fresh);
        $this->assertSame('admin', $fresh->username);
        $this->assertSame('Changed', $fresh->display_name);
    }

    public function test_invalid_username_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        User::factory()->create(['username' => 'Not Safe']);
    }

    private function setEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            return;
        }

        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
