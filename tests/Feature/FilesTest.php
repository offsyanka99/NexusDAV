<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\Dav\DavServerFactory;
use App\Services\Dav\FileStorage;
use App\Services\SettingsService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * PR 28: a private file home per user at /dav/files/<username>/.
 */
class FilesTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private string $outside;

    private User $ada;

    private User $bob;

    private string $adaToken;

    private string $bobToken;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir().'/nexusdav-files-test-'.bin2hex(random_bytes(6));
        mkdir($base);
        $this->root = $base.'/files';
        $this->outside = $base.'/outside';
        mkdir($this->outside);
        file_put_contents($this->outside.'/secret.txt', 'outside the homes');

        config([
            'nexusdav.files.path' => $this->root,
            'nexusdav.features.files' => true,
        ]);

        $this->ada = $this->user('ada');
        $this->bob = $this->user('bob');
        $this->adaToken = $this->token($this->ada);
        $this->bobToken = $this->token($this->bob);
    }

    protected function tearDown(): void
    {
        $base = dirname($this->root);
        (new FileStorage)->deleteTree($base);

        parent::tearDown();
    }

    public function test_files_is_off_by_default_answers_403_when_off_and_keeps_the_files(): void
    {
        $this->assertSame(['default' => false], ['default' => SettingsService::DEFINITIONS['files']['default']]);
        $this->assertSame('NEXUSDAV_FILES', app(SettingsService::class)->envName('files'));

        $this->ada('PUT', '/dav/files/ada/kept.txt', 'kept')->assertCreated();
        $this->assertContains('files', $this->rootNames());

        app(SettingsService::class)->save(['files' => false]);

        $this->assertFalse(app(SettingsService::class)->get('files'));
        $this->assertNotContains('files', $this->rootNames());
        foreach (['PROPFIND', 'GET', 'PUT', 'DELETE', 'MKCOL'] as $method) {
            $response = $this->ada($method, $method === 'MKCOL' ? '/dav/files/ada/new/' : '/dav/files/ada/kept.txt', $method === 'PUT' ? 'replaced' : null)->assertForbidden();
            $this->assertStringContainsString('Files is turned off on this server.', $this->body($response));
        }
        $this->ada('PROPFIND', '/dav/files/', null, ['Depth' => '0'])->assertForbidden();
        $this->assertSame('kept', file_get_contents($this->root.'/ada/kept.txt'));
        $this->assertDirectoryDoesNotExist($this->root.'/ada/new');

        app(SettingsService::class)->save(['files' => true]);

        $this->assertSame('kept', $this->body($this->ada('GET', '/dav/files/ada/kept.txt')->assertOk()));
    }

    public function test_a_missing_files_key_counts_as_off(): void
    {
        config(['nexusdav.features' => array_diff_key((array) config('nexusdav.features'), ['files' => true])]);

        $this->assertNotContains('files', $this->rootNames());
        $this->ada('PROPFIND', '/dav/files/ada/', null, ['Depth' => '0'])->assertForbidden();
    }

    public function test_the_home_is_created_on_first_use_and_serves_the_basic_methods(): void
    {
        $this->assertDirectoryDoesNotExist($this->root.'/ada');

        $this->ada('PROPFIND', '/dav/files/ada/', null, ['Depth' => '0'])->assertStatus(207);
        $this->assertDirectoryExists($this->root.'/ada');

        $this->ada('MKCOL', '/dav/files/ada/docs/')->assertCreated();
        $this->ada('MKCOL', '/dav/files/ada/docs/')->assertStatus(405);
        $this->ada('MKCOL', '/dav/files/ada/missing/child/')->assertStatus(409);

        $created = $this->ada('PUT', '/dav/files/ada/docs/a.txt', 'hello world')->assertCreated();
        $etag = (string) $created->headers->get('ETag');
        $this->assertNotSame('', $etag);
        $this->assertSame('hello world', file_get_contents($this->root.'/ada/docs/a.txt'));

        $get = $this->ada('GET', '/dav/files/ada/docs/a.txt')->assertOk();
        $this->assertSame('hello world', $this->body($get));
        $this->assertSame($etag, $get->headers->get('ETag'));
        $this->assertNotNull($get->headers->get('Last-Modified'));

        $head = $this->ada('HEAD', '/dav/files/ada/docs/a.txt')->assertOk();
        $this->assertSame('11', $head->headers->get('Content-Length'));

        $range = $this->ada('GET', '/dav/files/ada/docs/a.txt', null, ['Range' => 'bytes=6-9'])->assertStatus(206);
        $this->assertSame('worl', $this->body($range));
        $this->assertSame('bytes 6-9/11', $range->headers->get('Content-Range'));
        $this->assertSame('ld', $this->body($this->ada('GET', '/dav/files/ada/docs/a.txt', null, ['Range' => 'bytes=-2'])->assertStatus(206)));
        $this->ada('GET', '/dav/files/ada/docs/a.txt', null, ['Range' => 'bytes=20-30'])->assertStatus(416);

        $home = $this->body($this->ada('PROPFIND', '/dav/files/ada/', $this->propfind(), ['Depth' => '1'])->assertStatus(207));
        $this->assertStringContainsString('<d:href>/dav/files/ada/docs/</d:href>', $home);
        $this->assertStringNotContainsString('a.txt', $home);

        $docs = $this->body($this->ada('PROPFIND', '/dav/files/ada/docs/', $this->propfind(), ['Depth' => '1'])->assertStatus(207));
        $this->assertStringContainsString('<d:href>/dav/files/ada/docs/a.txt</d:href>', $docs);
        $this->assertStringContainsString('<d:getcontentlength>11</d:getcontentlength>', $docs);
        $this->assertStringContainsString('<d:getetag>'.htmlspecialchars($etag, ENT_XML1 | ENT_QUOTES).'</d:getetag>', $docs);

        $deep = $this->body($this->ada('PROPFIND', '/dav/files/ada/', $this->propfind(), ['Depth' => 'infinity'])->assertStatus(207));
        $this->assertStringNotContainsString('a.txt', $deep);

        $replaced = $this->ada('PUT', '/dav/files/ada/docs/a.txt', 'bye', ['If-Match' => $etag])->assertNoContent();
        $this->assertNotSame($etag, $replaced->headers->get('ETag'));
        $this->assertSame('bye', $this->body($this->ada('GET', '/dav/files/ada/docs/a.txt')->assertOk()));
        $this->ada('PUT', '/dav/files/ada/docs/a.txt', 'stale', ['If-Match' => $etag])->assertStatus(412);
        $this->ada('PUT', '/dav/files/ada/docs/a.txt', 'again', ['If-None-Match' => '*'])->assertStatus(412);
        $this->ada('PUT', '/dav/files/ada/empty.txt', '')->assertCreated();
        $this->assertSame('', file_get_contents($this->root.'/ada/empty.txt'));

        // Above 2 MB sabre's body stream is backed by a temporary file.
        $large = random_bytes(3 * 1024 * 1024 + 17);
        $this->ada('PUT', '/dav/files/ada/large.bin', $large)->assertCreated();
        $this->assertSame(hash('sha256', $large), hash_file('sha256', $this->root.'/ada/large.bin'));
        $this->assertSame(hash('sha256', $large), hash('sha256', $this->body($this->ada('GET', '/dav/files/ada/large.bin')->assertOk())));
        $this->assertSame(substr($large, 2_500_000, 100), $this->body($this->ada('GET', '/dav/files/ada/large.bin', null, ['Range' => 'bytes=2500000-2500099'])->assertStatus(206)));

        $this->ada('DELETE', '/dav/files/ada/docs/a.txt')->assertNoContent();
        $this->assertFileDoesNotExist($this->root.'/ada/docs/a.txt');
        $this->ada('GET', '/dav/files/ada/docs/a.txt')->assertNotFound();

        $this->ada('PUT', '/dav/files/ada/docs/b.txt', 'b')->assertCreated();
        $this->ada('MKCOL', '/dav/files/ada/docs/inner/')->assertCreated();
        $this->ada('PUT', '/dav/files/ada/docs/inner/c.txt', 'c')->assertCreated();
        $this->ada('DELETE', '/dav/files/ada/docs/')->assertNoContent();
        $this->assertDirectoryDoesNotExist($this->root.'/ada/docs');

        $this->ada('DELETE', '/dav/files/ada/')->assertForbidden();
        $this->assertDirectoryExists($this->root.'/ada');
        $this->assertSame([], glob($this->root.'/'.FileStorage::UPLOADS.'/*') ?: []);
    }

    public function test_another_users_home_answers_404_and_the_root_lists_only_your_own(): void
    {
        $this->ada('PUT', '/dav/files/ada/private.txt', 'ada only')->assertCreated();
        $this->ada('MKCOL', '/dav/files/ada/folder/')->assertCreated();

        foreach ([
            ['PROPFIND', '/dav/files/ada/', null],
            ['PROPFIND', '/dav/files/ada/private.txt', null],
            ['GET', '/dav/files/ada/private.txt', null],
            ['HEAD', '/dav/files/ada/private.txt', null],
            ['PUT', '/dav/files/ada/private.txt', 'bob was here'],
            ['DELETE', '/dav/files/ada/private.txt', null],
            ['DELETE', '/dav/files/ada/folder/', null],
            ['PROPFIND', '/dav/files/nobody/', null],
        ] as [$method, $url, $body]) {
            $this->bob($method, $url, $body, ['Depth' => '0'])->assertNotFound();
        }

        // A new name in a hidden home fails exactly like one under a missing
        // user, so the answer tells nothing about who exists.
        foreach ([
            ['PUT', 'new.txt', 'bob was here'],
            ['MKCOL', 'bobs/', null],
        ] as [$method, $name, $body]) {
            $hidden = $this->bob($method, '/dav/files/ada/'.$name, $body)->getStatusCode();
            $this->assertContains($hidden, [404, 409], $method);
            $this->assertSame($hidden, $this->bob($method, '/dav/files/nobody/'.$name, $body)->getStatusCode(), $method);
        }

        $this->assertSame('ada only', file_get_contents($this->root.'/ada/private.txt'));
        $this->assertFileDoesNotExist($this->root.'/ada/new.txt');
        $this->assertDirectoryExists($this->root.'/ada/folder');
        $this->assertDirectoryDoesNotExist($this->root.'/ada/bobs');

        $listing = $this->body($this->bob('PROPFIND', '/dav/files/', $this->propfind(), ['Depth' => '1'])->assertStatus(207));
        $this->assertStringContainsString('/dav/files/bob/', $listing);
        $this->assertStringNotContainsString('/dav/files/ada/', $listing);
    }

    public function test_path_escapes_and_planted_symbolic_links_are_refused(): void
    {
        $this->bob('PUT', '/dav/files/bob/secret.txt', 'bob only')->assertCreated();
        $this->ada('MKCOL', '/dav/files/ada/docs/')->assertCreated();

        foreach ([
            '/dav/files/ada/%2e%2e/bob/secret.txt',
            '/dav/files/ada/%2E%2E/bob/secret.txt',
            '/dav/files/ada/docs/%2e%2e%2f%2e%2e%2fbob%2fsecret.txt',
            '/dav/files/ada/..%2fbob%2fsecret.txt',
            '/dav/files/ada/.%2e/.%2e/outside/secret.txt',
            '/dav/files/ada/a%00b.txt',
        ] as $url) {
            $response = $this->ada('GET', $url);
            $this->assertContains($response->getStatusCode(), [403, 404], $url);
            $this->assertStringNotContainsString('bob only', $this->body($response), $url);
            $this->assertStringNotContainsString('outside the homes', $this->body($response), $url);
        }

        $this->ada('GET', '/dav/files/ada/../bob/secret.txt')->assertNotFound();
        $this->ada('GET', '/dav/files/ada/../../../etc/passwd')->assertNotFound();
        $this->ada('GET', '/dav/../../etc/passwd')->assertNotFound();

        $this->ada('PUT', '/dav/files/ada/%2e%2e/escaped.txt', 'x')->assertForbidden();
        $this->ada('PUT', '/dav/files/ada/docs/%2e%2e%2f%2e%2e%2fescaped.txt', 'x')->assertForbidden();
        $this->ada('MKCOL', '/dav/files/ada/%2e%2e/')->assertForbidden();
        $this->ada('DELETE', '/dav/files/ada/docs/%2e%2e/')->assertForbidden();
        $this->assertFileDoesNotExist($this->root.'/escaped.txt');
        $this->assertFileDoesNotExist(dirname($this->root).'/escaped.txt');
        $this->assertDirectoryExists($this->root.'/ada/docs');

        // Links planted on disk, by an admin or another process.
        symlink($this->root.'/bob', $this->root.'/ada/to-bob');
        symlink($this->outside.'/secret.txt', $this->root.'/ada/secret-link.txt');
        symlink($this->outside, $this->root.'/ada/docs/outside');
        symlink($this->outside.'/gone.txt', $this->root.'/ada/dangling.txt');

        $this->ada('GET', '/dav/files/ada/to-bob/secret.txt')->assertNotFound();
        $this->ada('PROPFIND', '/dav/files/ada/to-bob/', null, ['Depth' => '1'])->assertNotFound();
        $this->ada('GET', '/dav/files/ada/secret-link.txt')->assertNotFound();
        $this->ada('GET', '/dav/files/ada/docs/outside/secret.txt')->assertNotFound();

        $listing = $this->body($this->ada('PROPFIND', '/dav/files/ada/', $this->propfind(), ['Depth' => '1'])->assertStatus(207));
        $this->assertStringNotContainsString('to-bob', $listing);
        $this->assertStringNotContainsString('secret-link', $listing);
        $this->assertStringNotContainsString('dangling', $listing);

        $this->ada('PUT', '/dav/files/ada/secret-link.txt', 'overwritten')->assertForbidden();
        $this->ada('PUT', '/dav/files/ada/dangling.txt', 'created through a link')->assertForbidden();
        $this->ada('PUT', '/dav/files/ada/to-bob/planted.txt', 'x')->assertStatus($this->ada('PUT', '/dav/files/ada/missing/planted.txt', 'x')->getStatusCode());
        $this->ada('MKCOL', '/dav/files/ada/to-bob/')->assertForbidden();
        $this->ada('DELETE', '/dav/files/ada/to-bob/')->assertNotFound();
        $this->assertSame('outside the homes', file_get_contents($this->outside.'/secret.txt'));
        $this->assertFileDoesNotExist($this->outside.'/gone.txt');
        $this->assertFileDoesNotExist($this->root.'/bob/planted.txt');

        // Deleting a folder removes a link inside it, not what it points to.
        $this->ada('DELETE', '/dav/files/ada/docs/')->assertNoContent();
        $this->assertDirectoryDoesNotExist($this->root.'/ada/docs');
        $this->assertSame('outside the homes', file_get_contents($this->outside.'/secret.txt'));
        $this->assertSame('bob only', file_get_contents($this->root.'/bob/secret.txt'));
    }

    public function test_a_home_that_is_a_symbolic_link_is_not_served(): void
    {
        mkdir($this->root, 0750, true);
        symlink($this->outside, $this->root.'/ada');

        $response = $this->ada('PROPFIND', '/dav/files/ada/', $this->propfind(), ['Depth' => '1']);

        $response->assertStatus(500);
        $this->assertStringNotContainsString('secret.txt', $this->body($response));
        $this->ada('PUT', '/dav/files/ada/planted.txt', 'x')->assertStatus(500);
        $this->assertFileDoesNotExist($this->outside.'/planted.txt');
    }

    public function test_names_are_checked_and_stored_in_nfc(): void
    {
        $nfd = "Cafe\u{0301}.txt";
        $nfc = "Caf\u{00E9}.txt";

        $this->ada('PUT', '/dav/files/ada/'.rawurlencode($nfd), 'coffee')->assertCreated();
        $this->assertFileExists($this->root.'/ada/'.$nfc);
        $this->assertFileDoesNotExist($this->root.'/ada/'.$nfd);
        $this->assertSame('coffee', $this->body($this->ada('GET', '/dav/files/ada/'.rawurlencode($nfc))->assertOk()));
        $this->assertSame('coffee', $this->body($this->ada('GET', '/dav/files/ada/'.rawurlencode($nfd))->assertOk()));
        $this->ada('PUT', '/dav/files/ada/'.rawurlencode($nfc), 'tea')->assertNoContent();
        $this->assertCount(1, glob($this->root.'/ada/Caf*') ?: []);

        $this->ada('MKCOL', '/dav/files/ada/'.rawurlencode("Re\u{0301}sume\u{0301}").'/')->assertCreated();
        $this->assertDirectoryExists($this->root.'/ada/'."R\u{00E9}sum\u{00E9}");

        foreach (['a%01b.txt', 'tab%09.txt', 'del%7F.txt', 'line%0Abreak.txt', 'bad%FF.txt'] as $name) {
            $this->ada('PUT', '/dav/files/ada/'.$name, 'x')->assertStatus(400);
            $this->ada('MKCOL', '/dav/files/ada/'.$name.'/')->assertStatus(400);
        }

        $this->ada('PUT', '/dav/files/ada/'.str_repeat('a', 256), 'x')->assertStatus(400);
        $this->ada('PUT', '/dav/files/ada/'.str_repeat("\u{00E9}", 128), 'x')->assertStatus(400);
        $this->ada('PUT', '/dav/files/ada/'.str_repeat('a', 255), 'x')->assertCreated();
        $this->ada('PUT', '/dav/files/ada/'.rawurlencode(str_repeat("\u{00E9}", 127)), 'x')->assertCreated();
        $this->ada('PUT', '/dav/files/ada/'.rawurlencode('spaces and ünïcödé 文件.txt'), 'x')->assertCreated();
        $this->ada('PUT', '/dav/files/ada/.hidden', 'x')->assertCreated();

        // A name planted on disk that is not NFC could not be opened by the
        // name it would be listed under.
        file_put_contents($this->root.'/ada/'."plante\u{0301}.txt", 'x');
        $listing = $this->body($this->ada('PROPFIND', '/dav/files/ada/', $this->propfind(), ['Depth' => '1'])->assertStatus(207));
        $this->assertStringNotContainsString('plante', $listing);
        $this->assertStringContainsString(strtolower(rawurlencode('spaces and ünïcödé 文件.txt')), $listing);
        $this->assertStringContainsString('/dav/files/ada/.hidden', $listing);
    }

    public function test_a_failed_upload_leaves_nothing_and_keeps_the_old_file(): void
    {
        $storage = new FileStorage;
        $home = $storage->ensureHome('ada');
        file_put_contents($home.'/kept.txt', 'old');

        foreach ([$home.'/new.txt', $home.'/kept.txt'] as $target) {
            $stream = fopen('nexusdav-failing://upload', 'r');
            $this->assertIsResource($stream);

            try {
                $storage->write($target, $stream);
                $this->fail('The write should have failed.');
            } catch (RuntimeException) {
                // Expected.
            } finally {
                fclose($stream);
            }
        }

        $this->assertFileDoesNotExist($home.'/new.txt');
        $this->assertSame('old', file_get_contents($home.'/kept.txt'));
        $this->assertSame([], glob($this->root.'/'.FileStorage::UPLOADS.'/*') ?: []);

        // The uploads directory cannot be made: the PUT fails without a trace.
        rmdir($this->root.'/'.FileStorage::UPLOADS);
        file_put_contents($this->root.'/'.FileStorage::UPLOADS, 'in the way');

        $this->ada('PUT', '/dav/files/ada/failed.txt', 'data')->assertStatus(500);
        $this->ada('PUT', '/dav/files/ada/kept.txt', 'data')->assertStatus(500);
        $this->assertFileDoesNotExist($home.'/failed.txt');
        $this->assertSame('old', file_get_contents($home.'/kept.txt'));
    }

    public function test_stale_uploads_are_removed_and_fresh_ones_kept(): void
    {
        $storage = new FileStorage;
        $storage->ensureHome('ada');
        $this->ada('PUT', '/dav/files/ada/first.txt', 'x')->assertCreated();
        $uploads = $this->root.'/'.FileStorage::UPLOADS;
        file_put_contents($uploads.'/upload-stale', 'x');
        touch($uploads.'/upload-stale', time() - 2 * 86400);
        file_put_contents($uploads.'/upload-fresh', 'x');

        $this->ada('PUT', '/dav/files/ada/second.txt', 'x')->assertCreated();

        $this->assertFileDoesNotExist($uploads.'/upload-stale');
        $this->assertFileExists($uploads.'/upload-fresh');
    }

    public function test_deleting_a_user_deletes_the_file_home_with_its_locks_and_properties(): void
    {
        $adam = $this->user('adam');
        $adamToken = $this->token($adam);
        $this->ada('MKCOL', '/dav/files/ada/docs/')->assertCreated();
        $this->ada('PUT', '/dav/files/ada/docs/a.txt', 'a')->assertCreated();
        $this->dav('PUT', '/dav/files/adam/b.txt', 'adam', $adamToken, 'b')->assertCreated();
        symlink($this->outside, $this->root.'/ada/docs/outside');

        foreach (['files/ada', 'files/ada/docs/a.txt', 'files/adam/b.txt'] as $path) {
            DB::table('propertystorage')->insert(['path' => $path, 'name' => '{urn:test}note', 'valuetype' => 1, 'value' => 'x']);
            DB::table('locks')->insert(['owner' => 'test', 'timeout' => 3600, 'created' => time(), 'token' => bin2hex(random_bytes(8)), 'scope' => 1, 'depth' => 0, 'uri' => $path]);
        }

        $this->ada->delete();

        $this->assertDirectoryDoesNotExist($this->root.'/ada');
        $this->assertSame([], glob($this->root.'/'.FileStorage::UPLOADS.'/deleted-*') ?: []);
        $this->assertSame('outside the homes', file_get_contents($this->outside.'/secret.txt'));
        $this->assertSame(['files/adam/b.txt'], DB::table('propertystorage')->pluck('path')->all());
        $this->assertSame(['files/adam/b.txt'], DB::table('locks')->pluck('uri')->all());
        $this->assertSame('b', file_get_contents($this->root.'/adam/b.txt'));

        // A user created again with the same name starts with an empty home.
        $again = $this->user('ada');
        $this->assertDirectoryDoesNotExist($this->root.'/ada');
        $listing = $this->body($this->dav('PROPFIND', '/dav/files/ada/', 'ada', $this->token($again), $this->propfind(), ['Depth' => '1'])->assertStatus(207));
        $this->assertStringNotContainsString('docs', $listing);
    }

    public function test_deleting_a_user_without_a_file_home_works(): void
    {
        $this->bob->delete();

        $this->assertDirectoryDoesNotExist($this->root.'/bob');
        $this->assertDatabaseMissing('users', ['username' => 'bob']);
    }

    private function user(string $username): User
    {
        return app(UserService::class)->create([
            'username' => $username,
            'display_name' => ucfirst($username),
            'email' => $username.'@example.com',
        ]);
    }

    private function token(User $user): string
    {
        return app(AppPasswordService::class)->issue($user, 'laptop')['token'];
    }

    /**
     * @return list<string>
     */
    private function rootNames(): array
    {
        $names = array_map(static fn ($node): string => $node->getName(), app(DavServerFactory::class)->make()->tree->getNodeForPath('')->getChildren());
        sort($names);

        return $names;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function ada(string $method, string $uri, ?string $body = null, array $headers = []): TestResponse
    {
        return $this->dav($method, $uri, 'ada', $this->adaToken, $body, $headers);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function bob(string $method, string $uri, ?string $body = null, array $headers = []): TestResponse
    {
        return $this->dav($method, $uri, 'bob', $this->bobToken, $body, $headers);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function dav(string $method, string $uri, string $username, string $password, ?string $body = null, array $headers = []): TestResponse
    {
        $server = ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode($username.':'.$password)];

        foreach ($headers as $name => $value) {
            $server[$name === 'Content-Type' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call($method, $uri, [], [], [], $server, $body);
    }

    private function body(TestResponse $response): string
    {
        return $response->baseResponse instanceof StreamedResponse ? (string) $response->streamedContent() : (string) $response->getContent();
    }

    private function propfind(): string
    {
        return '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/><d:getcontentlength/><d:getetag/><d:getlastmodified/></d:prop></d:propfind>';
    }
}

/**
 * A stream that gives a few bytes and then fails, like a client that drops
 * the connection in the middle of an upload.
 */
final class FailingUploadStream
{
    /** @var resource|null */
    public $context;

    private bool $sent = false;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string|false
    {
        if ($this->sent) {
            return false;
        }

        $this->sent = true;

        return 'partial';
    }

    public function stream_eof(): bool
    {
        return false;
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }
}

if (! in_array('nexusdav-failing', stream_get_wrappers(), true)) {
    stream_wrapper_register('nexusdav-failing', FailingUploadStream::class);
}
