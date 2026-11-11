<?php

namespace Tests\Feature;

use App\Filament\Pages\Configuration;
use App\Models\User;
use App\Services\UserService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PR 47 of the Version 1.x.x plan: the Danger Zone on the Configuration page.
 */
class DangerZoneTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir().'/nexusdav-danger-test-'.bin2hex(random_bytes(6));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['nexusdav.env_set' => [], 'app.url' => 'https://dav.example.org', 'nexusdav.files.path' => $base.'/files']);
        $this->beforeApplicationDestroyed(static fn () => File::deleteDirectory($base));

        $users = app(UserService::class);
        $this->admin = $users->create(['username' => 'root', 'display_name' => 'Root', 'email' => 'root@example.com', 'is_admin' => true, 'password' => self::PASSWORD]);
        $users->create(['username' => 'ada', 'display_name' => 'Ada', 'email' => 'ada@example.com']);
        $users->create(['username' => 'bob', 'display_name' => 'Bob', 'email' => 'bob@example.com']);
        $this->actingAs($this->admin);
    }

    public function test_the_warning_lists_what_is_deleted_and_kept_then_asks_for_the_name_and_password(): void
    {
        Livewire::test(Configuration::class)
            ->assertSee('Danger Zone')
            ->mountAction($this->reset())
            ->assertMountedActionModalSee('Other users')
            ->assertMountedActionModalSee('Not counted yet')
            ->assertMountedActionModalSee('Your account and password, settings from the environment')
            ->callMountedAction()
            ->assertActionMounted('confirmReset')
            ->assertMountedActionModalSee('Type dav.example.org');

        $this->assertSame(3, DB::table('users')->count());
    }

    public function test_a_wrong_name_or_password_resets_nothing(): void
    {
        $logged = $this->captureLog();

        Livewire::test(Configuration::class)
            ->mountAction($this->reset())
            ->callMountedAction()
            ->setActionData(['host' => 'dav.example.com', 'password' => self::PASSWORD])
            ->callMountedAction()
            ->assertHasActionErrors(['host'])
            ->setActionData(['host' => 'dav.example.org', 'password' => 'wrong-password'])
            ->callMountedAction()
            ->assertHasActionErrors(['password'])
            ->assertActionMounted('confirmReset');

        $this->assertSame(3, DB::table('users')->count());
        $this->assertSame(['root'], $logged->admins);
    }

    public function test_wrong_passwords_are_limited_to_five_a_minute(): void
    {
        $page = Livewire::test(Configuration::class)
            ->mountAction($this->reset())
            ->callMountedAction();

        for ($try = 1; $try <= 5; $try++) {
            $page->setActionData(['host' => 'dav.example.org', 'password' => 'wrong-'.$try])
                ->callMountedAction()
                ->assertHasActionErrors(['password']);
        }

        $page->setActionData(['host' => 'dav.example.org', 'password' => self::PASSWORD])
            ->callMountedAction()
            ->assertHasActionErrors(['password'])
            ->assertMountedActionModalSee('Too many wrong passwords. Try again in a minute.');

        $this->assertSame(3, DB::table('users')->count());

        $this->travel(61)->seconds();
        $page->setActionData(['host' => 'dav.example.org', 'password' => self::PASSWORD])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_the_reset_runs_and_opens_the_dashboard(): void
    {
        Livewire::test(Configuration::class)
            ->mountAction($this->reset())
            ->callMountedAction()
            ->setActionData(['host' => 'DAV.example.org', 'password' => self::PASSWORD])
            ->callMountedAction()
            ->assertHasActionErrors(['host'])
            ->setActionData(['host' => 'dav.example.org', 'password' => self::PASSWORD])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('The server was reset to its defaults.')
            ->assertRedirect(Filament::getUrl());

        $this->assertSame(['root'], DB::table('users')->pluck('username')->all());
        $this->assertSame(['default'], DB::table('calendarinstances')->pluck('uri')->all());
        $this->get('/admin')->assertOk();
    }

    public function test_settings_can_be_exported_from_the_warning(): void
    {
        Livewire::test(Configuration::class)
            ->mountAction($this->reset())
            ->callAction('exportBeforeReset')
            ->assertFileDownloaded();

        $this->assertSame(3, DB::table('users')->count());
    }

    private function reset(): TestAction
    {
        return TestAction::make('resetToDefault')->schemaComponent('dangerZone', 'content');
    }

    private function captureLog(): object
    {
        $logged = new \stdClass;
        $logged->admins = [];

        Log::listen(function (MessageLogged $event) use ($logged): void {
            if ($event->message === 'Factory reset refused: wrong password') {
                $logged->admins[] = $event->context['admin'] ?? null;
            }
        });

        return $logged;
    }
}
