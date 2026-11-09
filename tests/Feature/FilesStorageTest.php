<?php

namespace Tests\Feature;

use App\Filament\Widgets\Diagnostics\DavErrorsTable;
use App\Filament\Widgets\FilesStorageWidget;
use App\Services\Dav\FileStorage;
use App\Services\FileUsage;
use App\Services\SettingsService;
use App\Services\UserService;
use App\Support\DavStatusCodes;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Files storage tile on the Dashboard and the status codes dialog on
 * Diagnostics.
 */
class FilesStorageTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $base = sys_get_temp_dir().'/nexusdav-files-test-'.bin2hex(random_bytes(6));
        mkdir($base);
        $this->root = $base.'/files';
        $this->outside = $base.'/outside';
        mkdir($this->outside);
        file_put_contents($this->outside.'/big.bin', str_repeat('x', 100000));

        config([
            'nexusdav.env_set' => [],
            'nexusdav.files.path' => $this->root,
            'nexusdav.features.files' => true,
        ]);
        Cache::flush();

        $this->actingAs(app(UserService::class)->create([
            'username' => 'root',
            'display_name' => 'Root',
            'email' => 'root@example.com',
            'is_admin' => true,
            'password' => 'correct-horse-battery',
        ]));
    }

    protected function tearDown(): void
    {
        (new FileStorage)->deleteTree(dirname($this->root));

        parent::tearDown();
    }

    public function test_a_scan_adds_up_the_homes_and_skips_links_and_uploads_in_progress(): void
    {
        $this->populate();
        $usage = app(FileUsage::class);

        $this->assertNull($usage->last());

        $result = $usage->scan();

        $this->assertNotNull($result);
        $this->assertSame(35, $result['bytes']);
        $this->assertSame(3, $result['files']);
        $this->assertSame(2, $result['users']);
        $this->assertTrue($result['complete']);
        $this->assertSame($result, $usage->last());
        $this->assertEqualsWithDelta(time(), $result['counted_at'], 5);
    }

    public function test_a_missing_files_folder_counts_as_nothing(): void
    {
        $result = app(FileUsage::class)->scan();

        $this->assertSame(['bytes' => 0, 'files' => 0, 'users' => 0, 'complete' => true], array_intersect_key($result ?? [], array_flip(['bytes', 'files', 'users', 'complete'])));
    }

    public function test_a_scan_stops_at_its_time_budget_and_says_so(): void
    {
        $this->populate();

        $result = app(FileUsage::class)->scan(0.0, 1);

        $this->assertNotNull($result);
        $this->assertFalse($result['complete']);
        $this->assertLessThan(3, $result['files']);

        $this->assertTrue(app(FileUsage::class)->scan()['complete']);
        $this->assertSame(3, app(FileUsage::class)->last()['files']);
    }

    public function test_only_one_scan_runs_at_a_time(): void
    {
        $this->populate();
        $lock = Cache::lock('nexusdav.files.usage.scan', 60);
        $this->assertTrue($lock->get());

        $this->assertNull(app(FileUsage::class)->scan());
        $this->assertNull(app(FileUsage::class)->last());

        $lock->release();
        $this->assertNotNull(app(FileUsage::class)->scan());
    }

    public function test_the_volume_numbers_come_from_the_disk(): void
    {
        $volume = app(FileUsage::class)->volume();

        $this->assertNotNull($volume);
        $this->assertGreaterThan(0, $volume['total']);
        $this->assertLessThanOrEqual($volume['total'], $volume['free']);
    }

    public function test_the_tile_is_hidden_while_files_is_off(): void
    {
        $this->assertTrue(FilesStorageWidget::canView());

        $this->get('/admin')->assertOk()->assertSee('Files storage');

        app(SettingsService::class)->save(['files' => false]);

        $this->assertFalse(FilesStorageWidget::canView());
        $this->get('/admin')->assertOk()->assertDontSee('Files storage');
    }

    public function test_the_tile_shows_the_last_count_and_loading_it_never_walks_the_files(): void
    {
        $this->populate();
        $this->app->instance(FileUsage::class, new class(app(FileStorage::class)) extends FileUsage
        {
            public function scan(float $budgetSeconds = self::BUDGET_SECONDS, int $checkEvery = 500): ?array
            {
                throw new \LogicException('A page load must not walk the files.');
            }
        });

        Livewire::test(FilesStorageWidget::class)
            ->assertSee('Files storage')
            ->assertSee('Not counted yet')
            ->assertSee('Free space')
            ->assertSee('The files are counted when you click Refresh.')
            ->assertSee('Refresh');

        $this->get('/admin')->assertOk()->assertSee('Not counted yet');

        Cache::forever(FileUsage::CACHE_KEY, ['bytes' => 5 * 1073741824 + 1073741824 / 2, 'files' => 12345, 'users' => 7, 'counted_at' => time(), 'seconds' => 2.4, 'complete' => true]);

        Livewire::test(FilesStorageWidget::class)
            ->assertSee('5.5 GB')
            ->assertSee('12,345')
            ->assertSee('Users with files')
            ->assertSee('in 2.4 seconds')
            ->assertDontSee('Not counted yet')
            ->assertDontSee('At least');
    }

    public function test_a_count_that_stopped_early_is_marked_as_a_minimum(): void
    {
        Cache::forever(FileUsage::CACHE_KEY, ['bytes' => 2048, 'files' => 40, 'users' => 3, 'counted_at' => time(), 'seconds' => 30.0, 'complete' => false]);

        Livewire::test(FilesStorageWidget::class)
            ->assertSee('At least 2 KB')
            ->assertSee('Stopped after 30 seconds, so the real total is higher.');
    }

    public function test_refresh_counts_the_files_and_tells_the_admin(): void
    {
        $this->populate();

        Livewire::test(FilesStorageWidget::class)
            ->callAction('refreshStorage')
            ->assertNotified('Counted')
            ->assertSee('35 B')
            ->assertDontSee('Not counted yet');

        $this->assertSame(35, app(FileUsage::class)->last()['bytes']);

        Livewire::test(FilesStorageWidget::class)
            ->assertSee('35 B')
            ->assertDontSee('Not counted yet');
    }

    public function test_refresh_while_another_count_runs_says_so_and_changes_nothing(): void
    {
        $this->populate();
        $lock = Cache::lock('nexusdav.files.usage.scan', 60);
        $lock->get();

        Livewire::test(FilesStorageWidget::class)
            ->callAction('refreshStorage')
            ->assertNotified('The files are already being counted');

        $this->assertNull(app(FileUsage::class)->last());
        $lock->release();
    }

    public function test_the_refresh_asks_for_confirmation_first(): void
    {
        Livewire::test(FilesStorageWidget::class)
            ->mountAction('refreshStorage')
            ->assertMountedActionModalSee('This walks every user\'s files and reads the disk.')
            ->assertMountedActionModalSee('up to 30 seconds');

        $this->assertNull(app(FileUsage::class)->last());
    }

    public function test_the_dav_errors_table_has_a_status_codes_dialog(): void
    {
        $page = Livewire::test(DavErrorsTable::class)->mountTableAction('statusCodes');

        foreach (DavStatusCodes::all() as $code) {
            $page->assertMountedActionModalSee($code['code'].' — '.$code['name']);
            $page->assertMountedActionModalSee($code['meaning']);
            $page->assertMountedActionModalSee($code['check']);
        }

        $page->assertMountedActionModalSee('What to check')
            ->assertMountedActionModalSee('Maximum upload size on the Settings page.');
    }

    public function test_the_status_codes_cover_what_the_server_answers_and_are_translated(): void
    {
        $codes = array_column(DavStatusCodes::all(), 'code');

        $this->assertSame([400, 401, 403, 404, 405, 409, 412, 413, 415, 416, 423, 500, 503, 507], $codes);

        $english = DavStatusCodes::all();
        app()->setLocale('ru');

        foreach (DavStatusCodes::all() as $index => $code) {
            $this->assertNotSame($english[$index]['meaning'], $code['meaning'], $code['code'].' meaning is not translated');
            $this->assertNotSame($english[$index]['check'], $code['check'], $code['code'].' check is not translated');
            $this->assertNotSame($english[$index]['name'], $code['name'], $code['code'].' name is not translated');
        }
    }

    /**
     * ada: 10 + 20 bytes in a folder tree, plus links that must not count.
     * bob: an empty home. carol: 5 bytes. Uploads in progress and a stray
     * file at the root are not users' files.
     */
    private function populate(): void
    {
        $storage = new FileStorage;

        foreach (['ada', 'bob', 'carol'] as $name) {
            $storage->ensureHome($name);
        }

        mkdir($this->root.'/ada/docs/deep', 0750, true);
        file_put_contents($this->root.'/ada/a.txt', str_repeat('a', 10));
        file_put_contents($this->root.'/ada/docs/deep/b.txt', str_repeat('b', 20));
        file_put_contents($this->root.'/carol/c.txt', str_repeat('c', 5));
        symlink($this->outside, $this->root.'/ada/link-to-outside');
        symlink($this->outside.'/big.bin', $this->root.'/ada/link-to-file');
        symlink($this->outside, $this->root.'/linked-home');
        mkdir($this->root.'/'.FileStorage::UPLOADS, 0750);
        file_put_contents($this->root.'/'.FileStorage::UPLOADS.'/upload-1', str_repeat('u', 1000));
        file_put_contents($this->root.'/stray.txt', 'not a home');
    }
}
