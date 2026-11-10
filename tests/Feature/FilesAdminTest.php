<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Widgets\ServicesWidget;
use App\Models\Setting;
use App\Models\User;
use App\Services\Dav\FileStorage;
use App\Services\SettingsService;
use App\Services\UserService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PR 31: Files on the Settings page, the user page, the delete confirmation
 * and the Services card.
 */
class FilesAdminTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $base = sys_get_temp_dir().'/nexusdav-files-test-'.bin2hex(random_bytes(6));
        mkdir($base);
        $this->root = $base.'/files';

        config([
            'nexusdav.env_set' => [],
            'nexusdav.files.path' => $this->root,
            'nexusdav.features.files' => false,
            'nexusdav.files.max_upload_mb' => 128,
        ]);

        $this->actingAs($this->makeUser('root', true));
    }

    protected function tearDown(): void
    {
        (new FileStorage)->deleteTree(dirname($this->root));

        parent::tearDown();
    }

    public function test_files_and_the_upload_limit_are_set_on_the_settings_page(): void
    {
        Livewire::test(Settings::class)
            ->assertSee('Files')
            ->assertSee('/dav/files/&lt;username&gt;/', false)
            ->assertFormFieldExists('files')
            ->assertFormFieldEnabled('files')
            ->assertFormSet(['files' => false])
            ->assertFormFieldHidden('files_max_upload_mb')
            ->fillForm(['files' => true])
            ->assertFormFieldVisible('files_max_upload_mb')
            ->assertFormSet(['files_max_upload_mb' => 128])
            ->assertSee('Above 128 MB, raise the request body limit of the reverse proxy too.')
            ->fillForm(['files_max_upload_mb' => 512])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Saved');

        $settings = app(SettingsService::class);
        $this->assertTrue($settings->get('files'));
        $this->assertSame(512, $settings->get('files_max_upload_mb'));
        $this->assertSame('true', Setting::query()->where('key', 'files')->value('value'));
        $this->assertSame('512', Setting::query()->where('key', 'files_max_upload_mb')->value('value'));

        Livewire::test(Settings::class)
            ->assertFormSet(['files' => true, 'files_max_upload_mb' => 512])
            ->fillForm(['files_max_upload_mb' => 0])
            ->call('save')
            ->assertHasFormErrors(['files_max_upload_mb']);

        // Switching Files off keeps the saved limit.
        Livewire::test(Settings::class)
            ->fillForm(['files' => false])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertFalse($settings->get('files'));
        $this->assertSame(512, $settings->get('files_max_upload_mb'));
    }

    public function test_set_variables_lock_both_fields(): void
    {
        config([
            'nexusdav.env_set' => ['NEXUSDAV_FILES', 'NEXUSDAV_FILES_MAX_UPLOAD_MB'],
            'nexusdav.features.files' => true,
            'nexusdav.files.max_upload_mb' => 1024,
        ]);

        Livewire::test(Settings::class)
            ->assertFormFieldDisabled('files')
            ->assertFormFieldDisabled('files_max_upload_mb')
            ->assertSee('Set by NEXUSDAV_FILES')
            ->assertSee('Set by NEXUSDAV_FILES_MAX_UPLOAD_MB')
            ->assertFormSet(['files' => true, 'files_max_upload_mb' => 1024])
            ->fillForm(['files' => false, 'files_max_upload_mb' => 5])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(app(SettingsService::class)->get('files'));
        $this->assertSame(1024, app(SettingsService::class)->get('files_max_upload_mb'));
        $this->assertSame(0, Setting::query()->whereIn('key', ['files', 'files_max_upload_mb'])->count());
    }

    public function test_the_user_page_shows_whether_the_file_home_exists(): void
    {
        $ada = $this->makeUser('ada');

        Livewire::test(EditUser::class, ['record' => $ada->getKey()])
            ->assertSee('File home')
            ->assertSee('Not created yet')
            ->assertDontSee('Exists');

        app(FileStorage::class)->ensureHome('ada');

        Livewire::test(EditUser::class, ['record' => $ada->getKey()])
            ->assertSee('File home')
            ->assertSee('Exists')
            ->assertDontSee('Not created yet');
    }

    public function test_the_delete_confirmation_says_the_file_home_goes(): void
    {
        $ada = $this->makeUser('ada');
        $bob = $this->makeUser('bob');
        app(FileStorage::class)->ensureHome('ada');
        file_put_contents($this->root.'/ada/kept.txt', 'x');
        $note = 'Their file home and every file in it will be deleted too.';

        foreach ([
            Livewire::test(EditUser::class, ['record' => $ada->getKey()])->mountAction('delete'),
            Livewire::test(ListUsers::class)->mountTableAction('delete', $ada),
        ] as $page) {
            $page->assertMountedActionModalSee($note);
        }

        Livewire::test(EditUser::class, ['record' => $bob->getKey()])
            ->mountAction('delete')
            ->assertMountedActionModalDontSee($note);

        Livewire::test(EditUser::class, ['record' => $ada->getKey()])
            ->callAction('delete');

        $this->assertDirectoryDoesNotExist($this->root.'/ada');
    }

    public function test_the_services_card_shows_files(): void
    {
        Livewire::test(ServicesWidget::class)
            ->assertSee('Files')
            ->assertDontSee('Set by environment');

        $card = strip_tags(Livewire::test(ServicesWidget::class)->html());
        $this->assertSame(2, substr_count($card, 'Off'), 'Only Files and WebDAV-Push are off by default.');

        app(SettingsService::class)->save(['files' => true]);
        $this->assertSame(1, substr_count(strip_tags(Livewire::test(ServicesWidget::class)->html()), 'Off'));

        config(['nexusdav.env_set' => ['NEXUSDAV_FILES']]);
        Livewire::test(ServicesWidget::class)->assertSee('Set by environment');
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
