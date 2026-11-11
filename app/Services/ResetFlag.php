<?php

namespace App\Services;

use App\Services\Dav\FileStorage;
use RuntimeException;
use Throwable;

/**
 * Says that a factory reset is running (PR 46 of the Version 1.x.x plan).
 *
 * A file next to the file storage root, on the data volume, so the web
 * container and the queue worker see the same flag. It is not in the cache,
 * because the reset empties the cache. It holds the time it was set and is
 * ignored after STALE_SECONDS, so a reset that died half way cannot keep the
 * server closed for good.
 */
final class ResetFlag
{
    public const STALE_SECONDS = 900;

    public const RETRY_AFTER_SECONDS = 30;

    private const NAME = '.nexusdav-resetting';

    public function __construct(private FileStorage $storage) {}

    public function active(): bool
    {
        try {
            $path = $this->path();
        } catch (Throwable) {
            return false;
        }

        clearstatcache(true, $path);

        if (! is_file($path)) {
            return false;
        }

        $since = (int) @file_get_contents($path);

        return $since > 0 && time() - $since < self::STALE_SECONDS;
    }

    public function set(): void
    {
        $path = $this->path();
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the folder of the reset flag.');
        }

        if (@file_put_contents($path, (string) time(), LOCK_EX) === false) {
            throw new RuntimeException('Could not set the reset flag.');
        }
    }

    public function clear(): void
    {
        try {
            $path = $this->path();
        } catch (Throwable) {
            return;
        }

        if (is_file($path)) {
            @unlink($path);
        }

        clearstatcache(true, $path);
    }

    public function path(): string
    {
        return dirname($this->storage->root()).'/'.self::NAME;
    }
}
