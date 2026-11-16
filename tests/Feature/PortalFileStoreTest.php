<?php

namespace Tests\Feature;

use App\Jobs\SendPushMessages;
use App\Models\User;
use App\Services\Dav\FileStorage;
use App\Services\Portal\Exceptions\AlreadyExists;
use App\Services\Portal\Exceptions\InvalidItem;
use App\Services\Portal\Exceptions\LockedItem;
use App\Services\Portal\Exceptions\NotFound;
use App\Services\Portal\FileStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PortalHelpers;
use Tests\TestCase;

/**
 * PR 50a, step 11: the portal's file writes keep the WebDAV rules (names,
 * links, the upload limit, locks) and push like the DAV plugin.
 */
class PortalFileStoreTest extends TestCase
{
    use PortalHelpers, RefreshDatabase;

    private string $root;

    private User $ada;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir().'/nexusdav-portal-files-'.bin2hex(random_bytes(6));
        mkdir($base);
        $this->root = $base.'/files';
        mkdir($base.'/outside');
        file_put_contents($base.'/outside/secret.txt', 'outside the homes');

        config([
            'nexusdav.files.path' => $this->root,
            'nexusdav.features.files' => true,
            'nexusdav.features.push' => true,
            'nexusdav.push.files' => true,
            'app.url' => 'https://dav.example.org',
        ]);
        $this->fakeDelivery();
        $this->ada = $this->user('ada');
        $this->token = $this->token($this->ada);
    }

    protected function tearDown(): void
    {
        (new FileStorage)->deleteTree(dirname($this->root));

        parent::tearDown();
    }

    public function test_folders_and_files_are_created_listed_read_and_seen_over_webdav(): void
    {
        $folder = $this->files()->createFolder($this->ada, '', 'Photos');
        $this->assertTrue($folder->folder);
        $this->assertSame('Photos', $folder->path);

        $file = $this->files()->write($this->ada, 'Photos', 'a.txt', 'hello');
        $this->assertSame('Photos/a.txt', $file->path);
        $this->assertSame(5, $file->size);
        $this->files()->write($this->ada, '', 'b.txt', 'b');
        $this->files()->createFolder($this->ada, '', 'Archive');

        $this->assertSame(['Archive', 'Photos', 'b.txt'], array_map(fn ($entry) => $entry->name, $this->files()->list($this->ada)));

        $stream = $this->files()->read($this->ada, 'Photos/a.txt');
        $this->assertSame('hello', stream_get_contents($stream));
        fclose($stream);

        $get = $this->dav('GET', '/dav/files/ada/Photos/a.txt', 'ada', $this->token)->assertOk();
        $this->assertSame($file->etag, $get->headers->get('ETag'));

        $this->files()->write($this->ada, 'Photos', 'a.txt', 'replaced');
        $this->assertSame('replaced', file_get_contents($this->root.'/ada/Photos/a.txt'));
    }

    public function test_names_follow_the_webdav_rules(): void
    {
        // NFD in, NFC on disk, as a WebDAV client's name.
        $entry = $this->files()->write($this->ada, '', "Cafe\u{0301}.txt", 'x');
        $this->assertSame("Caf\u{00E9}.txt", $entry->name);
        $this->assertFileExists($this->root."/ada/Caf\u{00E9}.txt");

        foreach (['', '.', '..', 'a/b', "bad\x01name", str_repeat('n', 256)] as $name) {
            $this->assertRefused(InvalidItem::class, fn () => $this->files()->write($this->ada, '', $name, 'x'));
        }

        $this->assertRefused(AlreadyExists::class, fn () => $this->files()->createFolder($this->ada, '', "Caf\u{00E9}.txt"));
        $this->files()->createFolder($this->ada, '', 'Docs');
        $this->assertRefused(AlreadyExists::class, fn () => $this->files()->write($this->ada, '', 'Docs', 'x'));
    }

    public function test_a_path_outside_the_home_and_a_link_are_refused(): void
    {
        $this->files()->createFolder($this->ada, '', 'Docs');
        symlink(dirname($this->root).'/outside', $this->root.'/ada/link');

        foreach (['..', '../outside/secret.txt', 'Docs/../../outside', '/etc/passwd', '%2e%2e/outside', 'Docs/%2F', 'link', 'link/secret.txt', 'missing', "Docs\0"] as $path) {
            $this->assertRefused(NotFound::class, fn () => $this->files()->read($this->ada, $path));
            $this->assertRefused(NotFound::class, fn () => $this->files()->list($this->ada, $path));
        }

        $this->assertRefused(NotFound::class, fn () => $this->files()->write($this->ada, 'link', 'x.txt', 'x'));
        $this->assertRefused(NotFound::class, fn () => $this->files()->delete($this->ada, '../outside'));
        $this->assertNotContains('link', array_map(fn ($entry) => $entry->name, $this->files()->list($this->ada)));
        $this->assertFileExists(dirname($this->root).'/outside/secret.txt');

        // Another user's home is outside this user's.
        $this->user('bob');
        $this->files()->write($this->user('cleo'), '', 'mine.txt', 'cleo');
        $this->assertRefused(NotFound::class, fn () => $this->files()->read($this->ada, '../cleo/mine.txt'));
    }

    public function test_the_upload_limit_applies(): void
    {
        config(['nexusdav.files.max_upload_mb' => 1]);

        $this->assertRefused(InvalidItem::class, fn () => $this->files()->write($this->ada, '', 'big.bin', 'x', 2 * 1048576));

        $refused = $this->assertRefused(InvalidItem::class, fn () => $this->files()->write($this->ada, '', 'big.bin', str_repeat('x', 1048577)));
        $this->assertFileDoesNotExist($this->root.'/ada/big.bin');
        $this->assertNotSame('', $refused->getMessage());

        $this->files()->write($this->ada, '', 'fits.bin', str_repeat('x', 1048576));
        $this->assertFileExists($this->root.'/ada/fits.bin');
        $this->assertNull(app(FileStorage::class)->uploadLimit());
    }

    public function test_a_locked_path_is_refused(): void
    {
        $this->files()->createFolder($this->ada, '', 'Shared');
        $this->files()->write($this->ada, 'Shared', 'doc.txt', 'v1');
        $this->files()->write($this->ada, '', 'free.txt', 'free');
        $this->files()->createFolder($this->ada, '', 'Elsewhere');
        $this->lock('files/ada/Shared/doc.txt', 0);

        $locked = $this->assertRefused(LockedItem::class, fn () => $this->files()->write($this->ada, 'Shared', 'doc.txt', 'v2'));
        $this->assertSame('Another app is editing this file.', $locked->title());
        $this->assertRefused(LockedItem::class, fn () => $this->files()->delete($this->ada, 'Shared/doc.txt'));
        $this->assertRefused(LockedItem::class, fn () => $this->files()->rename($this->ada, 'Shared/doc.txt', 'other.txt'));
        // A folder holding a locked file cannot go either.
        $this->assertRefused(LockedItem::class, fn () => $this->files()->delete($this->ada, 'Shared'));
        $this->assertRefused(LockedItem::class, fn () => $this->files()->move($this->ada, 'Shared', 'Elsewhere'));

        // A depth-infinity lock on a folder covers a new file in it.
        $this->lock('files/ada/Shared', 1);
        $this->assertRefused(LockedItem::class, fn () => $this->files()->write($this->ada, 'Shared', 'new.txt', 'x'));
        $this->assertRefused(LockedItem::class, fn () => $this->files()->move($this->ada, 'free.txt', 'Shared'));
        $this->assertSame('v1', file_get_contents($this->root.'/ada/Shared/doc.txt'));

        // An expired lock does not count.
        DB::table('locks')->update(['created' => time() - 3600, 'timeout' => 60]);
        $this->files()->write($this->ada, 'Shared', 'doc.txt', 'v2');
    }

    public function test_rename_move_and_delete_push_the_old_and_the_new_path(): void
    {
        $this->files()->createFolder($this->ada, '', 'A');
        $this->files()->createFolder($this->ada, '', 'B');
        $this->files()->write($this->ada, 'A', 'doc.txt', 'x');
        $a = $this->subscribe('/dav/files/ada/A/', 'ada', $this->token);
        $b = $this->subscribe('/dav/files/ada/B/', 'ada', $this->token);
        Queue::fake();

        $renamed = $this->files()->rename($this->ada, 'A/doc.txt', 'renamed.txt');
        $this->assertSame('A/renamed.txt', $renamed->path);
        $this->assertFileExists($this->root.'/ada/A/renamed.txt');
        $this->assertPushQueued($a->topic);
        $this->assertFalse(DB::table('push_outbox')->where('topic', $b->topic)->exists());

        DB::table('push_outbox')->delete();
        $moved = $this->files()->move($this->ada, 'A/renamed.txt', 'B');
        $this->assertSame('B/renamed.txt', $moved->path);
        $this->assertPushQueued($a->topic);
        $this->assertPushQueued($b->topic);

        DB::table('push_outbox')->delete();
        $this->files()->delete($this->ada, 'B/renamed.txt');
        $this->assertPushQueued($b->topic);
        $this->assertFileDoesNotExist($this->root.'/ada/B/renamed.txt');
        Queue::assertPushed(SendPushMessages::class);

        $this->assertRefused(InvalidItem::class, fn () => $this->files()->move($this->ada, 'A', 'A'));
        $this->files()->createFolder($this->ada, 'A', 'Inner');
        $this->assertRefused(InvalidItem::class, fn () => $this->files()->move($this->ada, 'A', 'A/Inner'));
        $this->assertRefused(InvalidItem::class, fn () => $this->files()->delete($this->ada, ''));
        $this->files()->write($this->ada, 'B', 'renamed.txt', 'y');
        $this->files()->write($this->ada, 'A', 'renamed.txt', 'z');
        $this->assertRefused(AlreadyExists::class, fn () => $this->files()->move($this->ada, 'A/renamed.txt', 'B'));
        $this->assertRefused(AlreadyExists::class, fn () => $this->files()->rename($this->ada, 'A/renamed.txt', 'Inner'));
    }

    public function test_files_off_hides_the_home(): void
    {
        config(['nexusdav.features.files' => false]);

        $this->assertRefused(NotFound::class, fn () => $this->files()->list($this->ada));
        $this->assertRefused(NotFound::class, fn () => $this->files()->write($this->ada, '', 'a.txt', 'x'));
    }

    private function files(): FileStore
    {
        return app(FileStore::class);
    }

    private function lock(string $uri, int $depth): void
    {
        DB::table('locks')->insert(['owner' => 'phone', 'timeout' => 600, 'created' => time(), 'token' => 'opaquelocktoken:'.bin2hex(random_bytes(8)), 'scope' => 1, 'depth' => $depth, 'uri' => $uri]);
    }
}
