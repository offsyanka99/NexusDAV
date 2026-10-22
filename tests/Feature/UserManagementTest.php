<?php

namespace Tests\Feature;

use App\Exceptions\LastActiveAdminException;
use App\Models\AppPassword;
use App\Models\Principal;
use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_user_creates_exactly_one_principal(): void
    {
        $user = app(UserService::class)->create([
            'username' => 'ada',
            'display_name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
        ]);

        $this->assertSame(1, Principal::query()->where('user_id', $user->getKey())->count());
        $this->assertSame('principals/ada', $user->principal?->uri);
        $this->assertSame('Ada Lovelace', $user->principal?->display_name);
        $this->assertSame('ada@example.com', $user->principal?->email);
        $this->assertNull($user->getAttributes()['password'] ?? null);
    }

    public function test_edit_keeps_principal_display_name_and_email_in_sync(): void
    {
        $users = app(UserService::class);
        $user = $users->create([
            'username' => 'ada',
            'display_name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
        ]);

        $users->update($user, [
            'username' => 'renamed',
            'display_name' => 'Augusta Ada',
            'email' => 'augusta@example.com',
        ]);

        $fresh = $user->fresh();
        $principal = $fresh?->principal;

        $this->assertNotNull($fresh);
        $this->assertNotNull($principal);
        $this->assertSame('ada', $fresh->username);
        $this->assertSame('Augusta Ada', $fresh->display_name);
        $this->assertSame('augusta@example.com', $fresh->email);
        $this->assertSame('Augusta Ada', $principal->display_name);
        $this->assertSame('augusta@example.com', $principal->email);
        $this->assertSame('principals/ada', $principal->uri);
        $this->assertSame(1, Principal::query()->count());
    }

    public function test_non_admin_can_be_created_without_a_password(): void
    {
        $user = app(UserService::class)->create([
            'username' => 'reader',
            'display_name' => 'Reader',
            'email' => 'reader@example.com',
            'is_admin' => false,
            'password' => null,
        ]);

        $this->assertFalse($user->is_admin);
        $this->assertNull($user->getAttributes()['password'] ?? null);
    }

    public function test_admin_must_have_a_password(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An admin must have a password.');

        app(UserService::class)->create([
            'username' => 'root',
            'display_name' => 'Root',
            'email' => 'root@example.com',
            'is_admin' => true,
        ]);
    }

    public function test_admin_password_stays_argon2id(): void
    {
        $user = app(UserService::class)->create([
            'username' => 'root',
            'display_name' => 'Root',
            'email' => 'root@example.com',
            'is_admin' => true,
            'password' => 'correct-horse-battery',
        ]);

        $stored = (string) $user->getAttributes()['password'];

        $this->assertStringStartsWith('$argon2id$', $stored);
        $this->assertNotSame('correct-horse-battery', $stored);
        $this->assertTrue(Hash::check('correct-horse-battery', $stored));
    }

    public function test_last_active_admin_cannot_be_deleted_disabled_or_demoted(): void
    {
        $users = app(UserService::class);
        $admin = $users->create([
            'username' => 'root',
            'display_name' => 'Root',
            'email' => 'root@example.com',
            'is_admin' => true,
            'password' => 'correct-horse-battery',
        ]);
        $other = $users->create([
            'username' => 'spare',
            'display_name' => 'Spare',
            'email' => 'spare@example.com',
            'is_admin' => true,
            'is_active' => false,
            'password' => 'another-secret-password',
        ]);

        try {
            $users->update($admin, ['is_active' => false]);
            $this->fail('The last active admin was disabled.');
        } catch (LastActiveAdminException $exception) {
            $this->assertSame(LastActiveAdminException::CANNOT_CHANGE, $exception->getMessage());
        }

        try {
            $users->update($admin->fresh() ?? $admin, ['is_admin' => false]);
            $this->fail('The last active admin was demoted.');
        } catch (LastActiveAdminException $exception) {
            $this->assertSame(LastActiveAdminException::CANNOT_CHANGE, $exception->getMessage());
        }

        try {
            $users->delete($admin->fresh() ?? $admin);
            $this->fail('The last active admin was deleted.');
        } catch (LastActiveAdminException $exception) {
            $this->assertSame(LastActiveAdminException::CANNOT_DELETE, $exception->getMessage());
        }

        $fresh = $admin->fresh();

        $this->assertNotNull($fresh);
        $this->assertTrue($fresh->is_admin);
        $this->assertTrue($fresh->is_active);
        $this->assertModelExists($admin);

        $users->delete($other->fresh() ?? $other);

        $this->assertModelMissing($other);
        $this->assertModelExists($admin);
    }

    public function test_a_second_active_admin_can_be_disabled(): void
    {
        $users = app(UserService::class);
        $users->create([
            'username' => 'root',
            'display_name' => 'Root',
            'email' => 'root@example.com',
            'is_admin' => true,
            'password' => 'correct-horse-battery',
        ]);
        $second = $users->create([
            'username' => 'spare',
            'display_name' => 'Spare',
            'email' => 'spare@example.com',
            'is_admin' => true,
            'password' => 'another-secret-password',
        ]);

        $users->update($second, ['is_active' => false]);

        $this->assertFalse($second->fresh()?->is_active);
    }

    public function test_blank_password_is_not_hashed(): void
    {
        $admin = app(UserService::class)->create([
            'username' => 'root',
            'display_name' => 'Root',
            'email' => 'root@example.com',
            'is_admin' => true,
            'password' => 'correct-horse-battery',
        ]);

        foreach (['', false] as $blank) {
            try {
                $admin->update(['password' => $blank]);
                $this->fail('A blank admin password was stored.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('An admin must have a password.', $exception->getMessage());
            }

            $fresh = $admin->fresh();

            $this->assertNotNull($fresh);
            $this->assertFalse(Hash::check('', (string) $fresh->password));
            $this->assertTrue(Hash::check('correct-horse-battery', (string) $fresh->password));
        }

        $user = app(UserService::class)->create([
            'username' => 'ada',
            'display_name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'a-real-password',
        ]);

        $user->update(['password' => '']);

        $this->assertNull($user->fresh()?->getAttributes()['password'] ?? null);
    }

    public function test_a_direct_create_syncs_its_principal_on_a_later_save_of_the_same_instance(): void
    {
        $user = User::query()->create([
            'username' => 'direct',
            'display_name' => 'Direct',
            'email' => 'direct@example.com',
            'password' => null,
            'is_admin' => false,
            'is_active' => true,
        ]);

        $this->assertSame('principals/direct', $user->principal?->uri);

        $user->update(['email' => 'direct2@example.com']);

        $this->assertSame('direct2@example.com', $user->email);
        $this->assertSame('direct2@example.com', $user->principal()->first()?->email);
        $this->assertSame(1, Principal::query()->where('user_id', $user->getKey())->count());
    }

    public function test_deleting_a_user_removes_the_principal_and_app_passwords(): void
    {
        $users = app(UserService::class);
        $users->create([
            'username' => 'root',
            'display_name' => 'Root',
            'email' => 'root@example.com',
            'is_admin' => true,
            'password' => 'correct-horse-battery',
        ]);
        $user = $users->create([
            'username' => 'ada',
            'display_name' => 'Ada',
            'email' => 'ada@example.com',
        ]);
        $issued = app(AppPasswordService::class)->issue($user, 'Phone');

        $users->delete($user);

        $this->assertModelMissing($user);
        $this->assertSame(0, Principal::query()->where('user_id', $user->getKey())->count());
        $this->assertSame(0, AppPassword::query()->whereKey($issued['app_password']->getKey())->count());
    }
}
