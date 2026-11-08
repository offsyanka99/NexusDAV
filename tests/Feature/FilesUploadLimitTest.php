<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\Dav\FileStorage;
use App\Services\SettingsService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * PR 30: Maximum upload size, streamed uploads, and a full disk.
 */
class FilesUploadLimitTest extends TestCase
{
    use RefreshDatabase;

    private const MB = 1048576;

    private string $root;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir().'/nexusdav-files-test-'.bin2hex(random_bytes(6));
        mkdir($base);
        $this->root = $base.'/files';

        config([
            'nexusdav.env_set' => [],
            'nexusdav.files.path' => $this->root,
            'nexusdav.features.files' => true,
            'nexusdav.files.max_upload_mb' => 1,
        ]);

        $this->token = $this->token($this->user('ada'));
    }

    protected function tearDown(): void
    {
        (new FileStorage)->deleteTree(dirname($this->root));

        parent::tearDown();
    }

    public function test_the_setting_has_its_variable_default_and_range(): void
    {
        $definition = SettingsService::DEFINITIONS['files_max_upload_mb'];
        $this->assertSame('NEXUSDAV_FILES_MAX_UPLOAD_MB', $definition['env']);
        $this->assertSame(128, $definition['default']);

        $settings = app(SettingsService::class);
        $settings->save(['files_max_upload_mb' => '256']);
        $this->assertSame(256, $settings->get('files_max_upload_mb'));
        $this->assertSame(256, config('nexusdav.files.max_upload_mb'));

        foreach ([0, 102401, 'big'] as $value) {
            try {
                $settings->save(['files_max_upload_mb' => $value]);
                $this->fail('A value of '.var_export($value, true).' should be refused.');
            } catch (InvalidArgumentException) {
                $this->assertSame(256, $settings->get('files_max_upload_mb'));
            }
        }
    }

    public function test_an_upload_over_the_limit_is_refused_from_its_content_length(): void
    {
        $this->upload('exact.bin', str_repeat('a', self::MB), true)->assertCreated();
        $this->assertSame(self::MB, filesize($this->disk('exact.bin')));

        $response = $this->upload('big.bin', str_repeat('b', self::MB + 1), true)->assertStatus(413);
        $this->assertStringContainsString('The file is larger than the upload limit of 1 MB.', $this->body($response));
        $this->assertFileDoesNotExist($this->disk('big.bin'));

        $this->upload('exact.bin', str_repeat('c', 2 * self::MB), true)->assertStatus(413);
        $this->assertSame(str_repeat('a', self::MB), file_get_contents($this->disk('exact.bin')));
        $this->assertNoUploadsLeft();

        // A Content-Length over the limit is refused before the body is read.
        $this->dav('PUT', 'announced.bin', 'small', ['Content-Length' => (string) (5 * self::MB)])->assertStatus(413);
        $this->assertFileDoesNotExist($this->disk('announced.bin'));
    }

    public function test_an_upload_without_content_length_is_counted_and_stopped(): void
    {
        $this->upload('small.bin', str_repeat('a', 1000))->assertCreated();

        $this->upload('big.bin', str_repeat('b', 2 * self::MB))->assertStatus(413);
        $this->upload('small.bin', str_repeat('c', 2 * self::MB))->assertStatus(413);

        $this->assertFileDoesNotExist($this->disk('big.bin'));
        $this->assertSame(str_repeat('a', 1000), file_get_contents($this->disk('small.bin')));
        $this->assertNoUploadsLeft();
    }

    public function test_an_upload_shorter_than_its_content_length_leaves_nothing(): void
    {
        $this->upload('kept.txt', 'old', true)->assertCreated();

        $this->dav('PUT', 'cut.txt', '0123456789', ['Content-Length' => '20'])->assertStatus(400);
        $this->dav('PUT', 'kept.txt', '0123456789', ['Content-Length' => '20'])->assertStatus(400);

        $this->assertFileDoesNotExist($this->disk('cut.txt'));
        $this->assertSame('old', file_get_contents($this->disk('kept.txt')));
        $this->assertNoUploadsLeft();
    }

    public function test_the_limit_is_for_uploads_to_files_only(): void
    {
        $this->upload('seed.txt', 'x', true)->assertCreated();
        file_put_contents($this->disk('large.bin'), str_repeat('l', 3 * self::MB));

        // A copy is not an upload.
        $this->dav('COPY', 'large.bin', null, ['Destination' => '/dav/files/ada/large-copy.bin'])->assertCreated();
        $this->assertSame(3 * self::MB, filesize($this->disk('large-copy.bin')));

        // Calendars keep their own limits.
        $event = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\nBEGIN:VEVENT\r\nUID:big\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260101T100000Z\r\nSUMMARY:Big\r\nDESCRIPTION:".str_repeat('d', (int) (1.5 * self::MB))."\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $this->call('PUT', '/dav/calendars/ada/default/big.ics', [], [], [], $this->server(['Content-Type' => 'text/calendar', 'Content-Length' => (string) strlen($event)]), $event)->assertCreated();

        // A calendar body is read into memory, so PHP's post_max_size still
        // applies to it; only an upload to a file home skips it.
        $over = (string) ($this->postMaxBytes() + 1);
        $this->call('PUT', '/dav/calendars/ada/default/huge.ics', [], [], [], $this->server(['Content-Type' => 'text/calendar', 'Content-Length' => $over]), 'x')->assertStatus(413);
        config(['nexusdav.files.max_upload_mb' => 1024]);
        $this->dav('PUT', 'announced.bin', 'x', ['Content-Length' => $over])->assertStatus(400);
    }

    private function postMaxBytes(): int
    {
        $value = trim((string) ini_get('post_max_size'));
        $number = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G' => $number * 1073741824,
            'M' => $number * 1048576,
            'K' => $number * 1024,
            default => $number,
        };
    }

    public function test_a_full_disk_answers_507_and_leaves_nothing(): void
    {
        if (! is_writable('/dev/full')) {
            $this->markTestSkipped('/dev/full is not available.');
        }

        $this->upload('kept.txt', 'old', true)->assertCreated();

        $this->app->instance(FileStorage::class, new class extends FileStorage
        {
            protected function openTemporary(string $path)
            {
                return @fopen('/dev/full', 'wb');
            }
        });

        $response = $this->upload('new.txt', str_repeat('n', 5000), true)->assertStatus(507);
        $this->assertStringContainsString('There is not enough free space on the server.', $this->body($response));
        $this->upload('kept.txt', str_repeat('n', 5000))->assertStatus(507);

        $this->assertFileDoesNotExist($this->disk('new.txt'));
        $this->assertSame('old', file_get_contents($this->disk('kept.txt')));
        $this->assertNoUploadsLeft();
    }

    public function test_a_large_upload_is_streamed_without_holding_it_in_memory(): void
    {
        config(['nexusdav.files.max_upload_mb' => 64]);
        $this->upload('warm.txt', 'x', true)->assertCreated();
        $body = random_bytes(24 * self::MB);
        $hash = hash('sha256', $body);

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $this->upload('large.bin', $body, true)->assertCreated();
        $grew = memory_get_peak_usage() - $before;

        $this->assertSame($hash, hash_file('sha256', $this->disk('large.bin')));
        $this->assertLessThan(12 * self::MB, $grew, 'The upload was held in memory: '.intdiv($grew, self::MB).' MB.');
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

    private function disk(string $name): string
    {
        return $this->root.'/ada/'.$name;
    }

    private function assertNoUploadsLeft(): void
    {
        $this->assertSame([], glob($this->root.'/'.FileStorage::UPLOADS.'/*') ?: []);
    }

    private function upload(string $name, string $body, bool $withLength = false): TestResponse
    {
        return $this->dav('PUT', $name, $body, $withLength ? ['Content-Length' => (string) strlen($body)] : []);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function dav(string $method, string $name, ?string $body = null, array $headers = []): TestResponse
    {
        return $this->call($method, '/dav/files/ada/'.$name, [], [], [], $this->server($headers), $body);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function server(array $headers): array
    {
        $server = ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode('ada:'.$this->token)];

        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = $value;
        }

        return $server;
    }

    private function body(TestResponse $response): string
    {
        return $response->baseResponse instanceof StreamedResponse ? (string) $response->streamedContent() : (string) $response->getContent();
    }
}
