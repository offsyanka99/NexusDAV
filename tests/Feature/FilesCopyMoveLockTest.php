<?php

namespace Tests\Feature;

use App\Events\DavServerCreated;
use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\Dav\FileStorage;
use App\Services\UserService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use PDOException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * PR 29: COPY, MOVE, locks and dead properties in a file home.
 */
class FilesCopyMoveLockTest extends TestCase
{
    use RefreshDatabase;

    private const HOME = '/dav/files/ada/';

    private string $root;

    private string $outside;

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

        config([
            'nexusdav.files.path' => $this->root,
            'nexusdav.features.files' => true,
        ]);

        $this->adaToken = $this->token($this->user('ada'));
        $this->bobToken = $this->token($this->user('bob'));
    }

    protected function tearDown(): void
    {
        (new FileStorage)->deleteTree(dirname($this->root));

        parent::tearDown();
    }

    public function test_files_and_folder_trees_are_renamed_moved_and_copied(): void
    {
        $this->upload('a.txt', 'alpha');
        $this->ada('MKCOL', 'docs/')->assertCreated();
        $this->upload('docs/x.txt', 'ex');
        $this->ada('MKCOL', 'docs/inner/')->assertCreated();
        $this->upload('docs/inner/y.txt', 'why');

        $etag = (string) $this->ada('GET', 'a.txt')->headers->get('ETag');
        $this->move('a.txt', 'b.txt')->assertCreated();
        $this->assertFileDoesNotExist($this->disk('a.txt'));
        $this->assertSame('alpha', $this->content('b.txt'));
        $this->assertSame($etag, $this->ada('GET', 'b.txt')->headers->get('ETag'));

        $this->copy('docs/', 'docs-copy/')->assertCreated();
        $this->assertSame('ex', $this->content('docs-copy/x.txt'));
        $this->assertSame('why', $this->content('docs-copy/inner/y.txt'));
        $this->assertSame('why', $this->content('docs/inner/y.txt'));

        $this->copy('docs/', 'shallow/', ['Depth' => '0'])->assertCreated();
        $this->assertDirectoryExists($this->disk('shallow'));
        $this->assertSame([], array_values(array_diff(scandir($this->disk('shallow')) ?: [], ['.', '..'])));

        $this->ada('MKCOL', 'archive/')->assertCreated();
        $this->move('docs-copy/', 'archive/docs/')->assertCreated();
        $this->assertDirectoryDoesNotExist($this->disk('docs-copy'));
        $this->assertSame('why', $this->content('archive/docs/inner/y.txt'));

        $this->move('b.txt', 'archive/b.txt')->assertCreated();
        $this->assertSame('alpha', $this->content('archive/b.txt'));
        $this->copy('archive/b.txt', 'docs/inner/b-copy.txt')->assertCreated();
        $this->assertSame('alpha', $this->content('docs/inner/b-copy.txt'));
        $this->assertSame('alpha', $this->content('archive/b.txt'));

        $this->move('archive/b.txt', rawurlencode("Cafe\u{0301}.txt"))->assertCreated();
        $this->assertFileExists($this->disk("Caf\u{00E9}.txt"));
        $this->move(rawurlencode("Caf\u{00E9}.txt"), 'bad%01name.txt')->assertStatus(400);
        $this->copy('docs/x.txt', str_repeat('n', 256))->assertStatus(400);
        $this->assertFileExists($this->disk("Caf\u{00E9}.txt"));

        $this->move('docs/', 'renamed/')->assertCreated();
        $this->assertSame('why', $this->content('renamed/inner/y.txt'));
        $this->assertDirectoryDoesNotExist($this->disk('docs'));
        $this->move('renamed/', 'docs/', ['Depth' => '0'])->assertStatus(400);

        $listing = $this->body($this->ada('PROPFIND', '', '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>', ['Depth' => '1'])->assertStatus(207));
        foreach (['archive/', 'renamed/', 'shallow/', 'Caf%c3%a9.txt'] as $name) {
            $this->assertStringContainsString('<d:href>'.self::HOME.$name.'</d:href>', $listing);
        }
        $this->assertSame([], glob($this->root.'/'.FileStorage::UPLOADS.'/*') ?: []);
    }

    public function test_overwrite_decides_whether_an_existing_destination_is_replaced(): void
    {
        $this->upload('a.txt', 'alpha');
        $this->upload('b.txt', 'beta');

        $this->copy('a.txt', 'b.txt', ['Overwrite' => 'F'])->assertStatus(412);
        $this->move('a.txt', 'b.txt', ['Overwrite' => 'F'])->assertStatus(412);
        $this->assertSame('beta', $this->content('b.txt'));
        $this->assertSame('alpha', $this->content('a.txt'));

        $this->copy('a.txt', 'b.txt', ['Overwrite' => 'T'])->assertNoContent();
        $this->assertSame('alpha', $this->content('b.txt'));

        $this->upload('c.txt', 'gamma');
        $this->move('c.txt', 'b.txt')->assertNoContent();
        $this->assertSame('gamma', $this->content('b.txt'));
        $this->assertFileDoesNotExist($this->disk('c.txt'));

        $this->ada('MKCOL', 'folder/')->assertCreated();
        $this->upload('folder/inside.txt', 'in');
        $this->move('folder/', 'b.txt')->assertNoContent();
        $this->assertDirectoryExists($this->disk('b.txt'));
        $this->assertSame('in', $this->content('b.txt/inside.txt'));

        $this->copy('a.txt', 'b.txt', ['Overwrite' => 'X'])->assertStatus(400);
    }

    public function test_copy_and_move_are_refused_outside_the_own_home(): void
    {
        $this->upload('a.txt', 'alpha');
        $this->ada('MKCOL', 'docs/')->assertCreated();
        $this->upload('docs/x.txt', 'ex');
        $this->bob('PUT', '/dav/files/bob/bob.txt', 'bob only')->assertCreated();

        $this->copy('docs/', 'docs/inside/')->assertStatus(409);
        $this->move('docs/', 'docs/inside/')->assertStatus(409);
        $this->copy('a.txt', 'a.txt')->assertForbidden();

        // Another user's home fails exactly like a username that does not exist.
        foreach (['COPY', 'MOVE'] as $method) {
            $hidden = $this->ada($method, 'a.txt', null, ['Destination' => '/dav/files/bob/stolen.txt'])->getStatusCode();
            $this->assertSame(409, $hidden, $method);
            $this->assertSame($hidden, $this->ada($method, 'a.txt', null, ['Destination' => '/dav/files/nobody/stolen.txt'])->getStatusCode(), $method);
            $this->ada($method, 'a.txt', null, ['Destination' => '/dav/files/bob/bob.txt'])->assertStatus(409);
            $this->bob($method, '/dav/files/ada/a.txt', null, ['Destination' => '/dav/files/bob/taken.txt'])->assertNotFound();
        }
        $this->assertSame(['bob.txt'], array_values(array_diff(scandir($this->root.'/bob') ?: [], ['.', '..'])));
        $this->assertSame('bob only', file_get_contents($this->root.'/bob/bob.txt'));
        $this->assertSame('alpha', $this->content('a.txt'));

        // The home itself cannot be moved or copied: not next to another
        // home (that name is another user's), not into itself.
        $this->ada('MOVE', '', null, ['Destination' => '/dav/files/ada2/'])->assertStatus(409);
        $this->ada('COPY', '', null, ['Destination' => '/dav/files/ada2/'])->assertStatus(409);
        $this->ada('MOVE', '', null, ['Destination' => '/dav/files/'])->assertForbidden();
        $this->ada('MOVE', '', null, ['Destination' => self::HOME.'docs/home/'])->assertStatus(409);
        $this->assertDirectoryExists($this->root.'/ada');
        $this->assertDirectoryDoesNotExist($this->root.'/ada2');

        // Escapes in the Destination header.
        $this->copy('a.txt', '%2e%2e/bob/escaped.txt')->assertForbidden();
        $this->move('a.txt', 'docs/%2e%2e%2f%2e%2e%2fescaped.txt')->assertForbidden();
        $this->ada('COPY', 'a.txt', null, ['Destination' => '/elsewhere/escaped.txt'])->assertForbidden();
        $this->assertFileDoesNotExist($this->root.'/bob/escaped.txt');
        $this->assertFileDoesNotExist($this->root.'/escaped.txt');

        // A name taken by a symbolic link on disk is not written through.
        symlink($this->outside, $this->root.'/ada/link');
        $this->copy('a.txt', 'link')->assertForbidden();
        $this->move('docs/', 'link')->assertForbidden();
        $this->assertSame([], array_values(array_diff(scandir($this->outside) ?: [], ['.', '..'])));
        $this->assertDirectoryExists($this->disk('docs'));

        // Files and calendars do not mix.
        $files = $this->ada('COPY', 'a.txt', null, ['Destination' => '/dav/calendars/ada/default/a.ics']);
        $files->assertForbidden();
        $this->assertStringContainsString('Files can only be copied or moved inside the file home.', $this->body($files));
        $this->dav('MOVE', '/dav/calendars/ada/default/', 'ada', $this->adaToken, null, ['Destination' => 'https://dav.example.com'.self::HOME.'calendar/'])->assertForbidden();
        $this->assertDirectoryDoesNotExist($this->disk('calendar'));
        $this->dav('PROPFIND', '/dav/calendars/ada/default/', 'ada', $this->adaToken, null, ['Depth' => '0'])->assertStatus(207);
    }

    public function test_dead_properties_follow_copy_and_move_and_go_with_delete(): void
    {
        $this->ada('MKCOL', 'docs/')->assertCreated();
        $this->upload('docs/x.txt', 'ex');
        $this->ada('MKCOL', 'docs/inner/')->assertCreated();
        $this->upload('docs/inner/y.txt', 'why');
        $this->setProperty('docs/', 'tag', 'folder');
        $this->setProperty('docs/x.txt', 'color', 'red');
        $this->setProperty('docs/inner/y.txt', 'color', 'blue');

        $this->copy('docs/', 'copy/')->assertCreated();
        $this->assertSame('folder', $this->property('copy/', 'tag'));
        $this->assertSame('red', $this->property('copy/x.txt', 'color'));
        $this->assertSame('blue', $this->property('copy/inner/y.txt', 'color'));
        $this->assertSame('red', $this->property('docs/x.txt', 'color'));

        $this->copy('docs/', 'shallow/', ['Depth' => '0'])->assertCreated();
        $this->assertSame('folder', $this->property('shallow/', 'tag'));
        $this->assertSame(['files/ada/shallow'], $this->propertyPaths('files/ada/shallow'));

        // An overwritten destination loses its own properties.
        $this->upload('target.txt', 'old');
        $this->setProperty('target.txt', 'stale', 'yes');
        $this->copy('docs/x.txt', 'target.txt')->assertNoContent();
        $this->assertSame('red', $this->property('target.txt', 'color'));
        $this->assertNull($this->property('target.txt', 'stale'));

        $this->move('copy/', 'moved/')->assertCreated();
        $this->assertSame('folder', $this->property('moved/', 'tag'));
        $this->assertSame('blue', $this->property('moved/inner/y.txt', 'color'));
        $this->assertSame([], $this->propertyPaths('files/ada/copy'));

        $this->move('moved/x.txt', 'renamed.txt')->assertCreated();
        $this->assertSame('red', $this->property('renamed.txt', 'color'));

        $this->ada('DELETE', 'moved/')->assertNoContent();
        $this->ada('DELETE', 'renamed.txt')->assertNoContent();
        $this->assertSame([], $this->propertyPaths('files/ada/moved'));
        $this->assertSame([], $this->propertyPaths('files/ada/renamed.txt'));
        $this->assertSame(['files/ada/docs', 'files/ada/docs/inner/y.txt', 'files/ada/docs/x.txt'], $this->propertyPaths('files/ada/docs'));

        // A name that only shares a prefix keeps its properties.
        $this->ada('MKCOL', 'doc/')->assertCreated();
        $this->setProperty('doc/', 'tag', 'neighbour');
        $this->ada('DELETE', 'docs/')->assertNoContent();
        $this->assertSame('neighbour', $this->property('doc/', 'tag'));
    }

    public function test_locks_refuse_writes_without_the_token_and_go_with_move_and_delete(): void
    {
        $this->upload('a.txt', 'alpha');
        $token = $this->lock('a.txt');

        $this->upload('a.txt', 'no token', [], 423);
        $this->ada('DELETE', 'a.txt')->assertStatus(423);
        $this->move('a.txt', 'b.txt')->assertStatus(423);
        $this->lockRequest('a.txt')->assertStatus(423);
        $this->ada('PROPPATCH', 'a.txt', $this->proppatch('color', 'red'))->assertStatus(423);

        $this->upload('a.txt', 'with token', ['If' => '(<'.$token.'>)'], 204);
        $this->assertSame('with token', $this->content('a.txt'));

        // A copy is not locked.
        $this->copy('a.txt', 'copy.txt')->assertCreated();
        $this->upload('copy.txt', 'free', [], 204);

        // MOVE with the token: the lock stays behind and is released.
        $this->move('a.txt', 'b.txt', ['If' => '(<'.$token.'>)'])->assertCreated();
        $this->assertSame([], $this->lockPaths());
        $this->upload('b.txt', 'unlocked', [], 204);

        // Shared locks live together; an exclusive one waits for both.
        $first = $this->lock('b.txt', 'shared');
        $second = $this->lock('b.txt', 'shared');
        $this->lockRequest('b.txt')->assertStatus(423);
        $this->unlock('b.txt', $first)->assertNoContent();
        $this->unlock('b.txt', $second)->assertNoContent();
        $this->lock('b.txt');

        // A depth-infinity lock on a folder covers what is inside.
        $this->ada('MKCOL', 'docs/')->assertCreated();
        $this->upload('docs/x.txt', 'ex');
        $folder = $this->lock('docs/', 'exclusive', 'infinity');
        $this->upload('docs/new.txt', 'no token', [], 423);
        $this->ada('MKCOL', 'docs/sub/')->assertStatus(423);
        $this->upload('docs/new.txt', 'token', ['If' => '<'.$this->url('docs/').'> (<'.$folder.'>)'], 201);
        $this->ada('DELETE', 'docs/')->assertStatus(423);
        $this->ada('DELETE', 'docs/', null, ['If' => '(<'.$folder.'>)'])->assertNoContent();
        $this->assertDirectoryDoesNotExist($this->disk('docs'));
        $this->assertSame(['files/ada/b.txt'], $this->lockPaths());

        // LOCK on a new name creates an empty file.
        $this->lockRequest('new.txt')->assertCreated();
        $this->assertSame('', $this->content('new.txt'));
        $this->assertContains('files/ada/new.txt', $this->lockPaths());

        // Another user's file cannot be locked, unlocked or written, and its
        // lock does not show: 404 as for any path in another home.
        $this->dav('LOCK', self::HOME.'b.txt', 'bob', $this->bobToken, $this->lockInfo('exclusive'), ['Content-Type' => 'application/xml'])->assertNotFound();
        $this->dav('UNLOCK', self::HOME.'b.txt', 'bob', $this->bobToken, null, ['Lock-Token' => '<opaquelocktoken:none>'])->assertNotFound();
        $this->dav('PUT', self::HOME.'b.txt', 'bob', $this->bobToken, 'bob')->assertNotFound();
        $this->dav('PUT', self::HOME.'none.txt', 'bob', $this->bobToken, 'bob')->assertNotFound();
        $this->dav('PROPFIND', self::HOME.'b.txt', 'bob', $this->bobToken, null, ['Depth' => '0'])->assertNotFound();

        // The same for a calendar object.
        $event = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\nBEGIN:VEVENT\r\nUID:locked\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260101T100000Z\r\nSUMMARY:Locked\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $this->dav('PUT', '/dav/calendars/ada/default/locked.ics', 'ada', $this->adaToken, $event, ['Content-Type' => 'text/calendar'])->assertCreated();
        $this->dav('LOCK', '/dav/calendars/ada/default/locked.ics', 'ada', $this->adaToken, $this->lockInfo('exclusive'), ['Content-Type' => 'application/xml'])->assertOk();
        $this->dav('PUT', '/dav/calendars/ada/default/locked.ics', 'bob', $this->bobToken, $event, ['Content-Type' => 'text/calendar'])->assertNotFound();
        $this->dav('LOCK', '/dav/calendars/ada/default/locked.ics', 'bob', $this->bobToken, $this->lockInfo('exclusive'), ['Content-Type' => 'application/xml'])->assertNotFound();
        $this->dav('COPY', '/dav/calendars/bob/default/', 'bob', $this->bobToken, null, ['Destination' => '/dav/calendars/ada/default/locked.ics'])->assertStatus(409);
    }

    public function test_a_busy_database_during_a_file_write_is_not_replayed(): void
    {
        $this->upload('a.txt', 'alpha');
        $attempts = 0;
        Event::listen(DavServerCreated::class, function (DavServerCreated $event) use (&$attempts): void {
            $event->server->on('afterMove', function () use (&$attempts): void {
                $attempts++;

                throw new QueryException('sqlite', 'update "propertystorage"', [], new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'));
            });
        });

        $this->move('a.txt', 'b.txt')->assertStatus(503);

        $this->assertSame(1, $attempts);
        $this->assertSame('alpha', $this->content('b.txt'));
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

    private function url(string $path): string
    {
        return self::HOME.$path;
    }

    private function disk(string $path): string
    {
        return $this->root.'/ada/'.rtrim($path, '/');
    }

    private function content(string $path): string
    {
        $this->assertFileExists($this->disk($path));

        return (string) file_get_contents($this->disk($path));
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function upload(string $path, string $body, array $headers = [], int $status = 201): void
    {
        $this->ada('PUT', $path, $body, $headers)->assertStatus($status);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function copy(string $from, string $to, array $headers = []): TestResponse
    {
        return $this->ada('COPY', $from, null, ['Destination' => 'https://dav.example.com'.self::HOME.$to] + $headers);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function move(string $from, string $to, array $headers = []): TestResponse
    {
        return $this->ada('MOVE', $from, null, ['Destination' => self::HOME.$to] + $headers);
    }

    private function lock(string $path, string $scope = 'exclusive', string $depth = '0'): string
    {
        $response = $this->lockRequest($path, $scope, $depth)->assertOk();
        $token = trim((string) $response->headers->get('Lock-Token'), '<>');
        $this->assertStringStartsWith('opaquelocktoken:', $token);

        return $token;
    }

    private function lockRequest(string $path, string $scope = 'exclusive', string $depth = '0'): TestResponse
    {
        return $this->ada('LOCK', $path, $this->lockInfo($scope), ['Content-Type' => 'application/xml', 'Depth' => $depth, 'Timeout' => 'Second-600']);
    }

    private function unlock(string $path, string $token): TestResponse
    {
        return $this->ada('UNLOCK', $path, null, ['Lock-Token' => '<'.$token.'>']);
    }

    private function lockInfo(string $scope): string
    {
        return '<?xml version="1.0"?><d:lockinfo xmlns:d="DAV:"><d:lockscope><d:'.$scope.'/></d:lockscope><d:locktype><d:write/></d:locktype><d:owner>ada</d:owner></d:lockinfo>';
    }

    /**
     * @return list<string>
     */
    private function lockPaths(): array
    {
        return DB::table('locks')->orderBy('uri')->pluck('uri')->map(fn ($uri): string => (string) $uri)->all();
    }

    private function setProperty(string $path, string $name, string $value): void
    {
        $body = $this->body($this->ada('PROPPATCH', $path, $this->proppatch($name, $value))->assertStatus(207));
        $this->assertStringContainsString('200 OK', $body);
    }

    private function proppatch(string $name, string $value): string
    {
        return '<?xml version="1.0"?><d:propertyupdate xmlns:d="DAV:" xmlns:t="urn:test"><d:set><d:prop><t:'.$name.'>'.$value.'</t:'.$name.'></d:prop></d:set></d:propertyupdate>';
    }

    private function property(string $path, string $name): ?string
    {
        $body = $this->body($this->ada('PROPFIND', $path, '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:t="urn:test"><d:prop><t:'.$name.'/></d:prop></d:propfind>', ['Depth' => '0'])->assertStatus(207));

        if (preg_match('#<x\d+:'.$name.' xmlns:x\d+="urn:test">([^<]*)</x\d+:'.$name.'>#', $body, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    /**
     * Stored property paths at or under the prefix.
     *
     * @return list<string>
     */
    private function propertyPaths(string $prefix): array
    {
        return DB::table('propertystorage')
            ->where(fn ($query) => $query->where('path', $prefix)->orWhere('path', 'like', $prefix.'/%'))
            ->orderBy('path')
            ->distinct()
            ->pluck('path')
            ->map(fn ($path): string => (string) $path)
            ->all();
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function ada(string $method, string $path, ?string $body = null, array $headers = []): TestResponse
    {
        return $this->dav($method, self::HOME.$path, 'ada', $this->adaToken, $body, $headers);
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
}
