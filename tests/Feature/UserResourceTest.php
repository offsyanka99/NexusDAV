<?php

namespace Tests\Feature;

use App\Exceptions\LastActiveAdminException;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\RelationManagers\AppPasswordsRelationManager;
use App\Models\AppPassword;
use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\UserService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class UserResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_can_search_create_and_edit_users(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $match = User::factory()->create([
            'username' => 'searchme',
            'display_name' => 'Visible Person',
            'email' => 'visible@example.com',
        ]);
        $other = User::factory()->create([
            'username' => 'otheruser',
            'display_name' => 'Hidden Person',
            'email' => 'hidden@example.com',
        ]);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->searchTable('searchme')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);

        Livewire::test(ListUsers::class)
            ->searchTable('Visible')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);

        Livewire::test(ListUsers::class)
            ->searchTable('visible@example.com')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'username' => 'ada',
                'display_name' => 'Ada Lovelace',
                'email' => 'ada@example.com',
                'is_admin' => false,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::query()->where('username', 'ada')->first();

        $this->assertNotNull($created);
        $this->assertNull($created->getAttributes()['password'] ?? null);
        $this->assertSame('principals/ada', $created->principal?->uri);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'username' => 'root2',
                'display_name' => 'Second',
                'email' => 'root2@example.com',
                'is_admin' => true,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['password' => 'required']);

        Livewire::test(EditUser::class, ['record' => $created->getKey()])
            ->fillForm([
                'username' => 'renamed',
                'display_name' => 'Augusta',
                'email' => 'augusta@example.com',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $created->refresh();

        $this->assertSame('ada', $created->username);
        $this->assertSame('Augusta', $created->display_name);
        $this->assertSame('augusta@example.com', $created->email);
        $this->assertSame('Augusta', $created->principal?->display_name);
        $this->assertSame('augusta@example.com', $created->principal?->email);
        $this->assertSame('principals/ada', $created->principal?->uri);
    }

    public function test_last_active_admin_cannot_be_deleted_or_disabled_from_the_ui(): void
    {
        $admin = User::factory()->create([
            'username' => 'root',
            'is_admin' => true,
            'is_active' => true,
        ]);

        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $admin->getKey()])
            ->assertSee(LastActiveAdminException::CANNOT_CHANGE)
            ->fillForm(['is_admin' => false])
            ->call('save')
            ->assertNotified('Cannot change the last active admin');

        $this->assertTrue($admin->fresh()?->is_admin);

        Livewire::test(ListUsers::class)
            ->callTableAction('disable', $admin)
            ->assertNotified('Cannot disable the last active admin');

        $this->assertTrue($admin->fresh()?->is_active);

        Livewire::test(EditUser::class, ['record' => $admin->getKey()])
            ->callAction('delete')
            ->assertNotified('Cannot delete the last active admin');

        $this->assertModelExists($admin);
    }

    public function test_admin_can_disable_and_delete_another_user(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $user = app(UserService::class)->create([
            'username' => 'ada',
            'display_name' => 'Ada',
            'email' => 'ada@example.com',
        ]);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->callTableAction('disable', $user);

        $this->assertFalse($user->fresh()?->is_active);

        Livewire::test(ListUsers::class)
            ->callTableAction('enable', $user->fresh());

        $this->assertTrue($user->fresh()?->is_active);

        Livewire::test(ListUsers::class)
            ->callTableAction('delete', $user);

        $this->assertModelMissing($user);
    }

    public function test_generating_an_app_password_shows_it_once_and_revoke_keeps_the_row(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $user = app(UserService::class)->create([
            'username' => 'ada',
            'display_name' => 'Ada',
            'email' => 'ada@example.com',
        ]);

        $this->actingAs($admin);

        $component = Livewire::test(AppPasswordsRelationManager::class, [
            'ownerRecord' => $user,
            'pageClass' => EditUser::class,
        ])
            ->callTableAction('generate', data: [
                'name' => 'Pixel 7',
            ])
            ->assertNotified('App password created');

        $token = $component->instance()->plainTextToken;

        $this->assertIsString($token);
        $component->assertSee($token);

        $secret = explode(AppPasswordService::SEPARATOR, $token, 2)[1];
        $stored = json_encode(DB::table('app_passwords')->first());

        $this->assertIsString($stored);
        $this->assertStringNotContainsString($secret, $stored);
        $this->assertStringNotContainsString($token, $stored);

        $appPassword = AppPassword::query()->first();

        $this->assertNotNull($appPassword);

        Livewire::test(AppPasswordsRelationManager::class, [
            'ownerRecord' => $user,
            'pageClass' => EditUser::class,
        ])
            ->callTableAction('revoke', $appPassword)
            ->assertNotified('App password revoked');

        $this->assertNotNull($appPassword->fresh()?->revoked_at);
        $this->assertSame(1, AppPassword::query()->count());
    }
}
