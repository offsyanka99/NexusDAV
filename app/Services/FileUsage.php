<?php

namespace App\Services;

use App\Services\Dav\FileStorage;
use FilesystemIterator;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use UnexpectedValueException;

/**
 * How much space the users' files take, for the Dashboard.
 *
 * The only exact way to know is to walk every file, which is slow on a large
 * library, so nothing here runs while a page loads. scan() runs when an admin
 * asks for it; last() only reads the result it left in the cache, and
 * volume() is two cheap system calls. A scan stops after a time budget and
 * says so, so a huge tree cannot hold a worker for ever, and a lock stops two
 * scans at once. It never follows a symbolic link and skips the uploads in
 * progress.
 */
class FileUsage
{
    public const CACHE_KEY = 'nexusdav.files.usage';

    public const BUDGET_SECONDS = 30.0;

    private const LOCK_KEY = 'nexusdav.files.usage.scan';

    private const CHECK_EVERY = 500;

    public function __construct(private FileStorage $storage) {}

    /**
     * The result of the last scan. Null until a scan has run.
     *
     * @return array{bytes: int, files: int, users: int, counted_at: int, seconds: float, complete: bool}|null
     */
    public function last(): ?array
    {
        $value = Cache::get(self::CACHE_KEY);

        return is_array($value) && isset($value['bytes'], $value['files'], $value['users'], $value['counted_at']) ? $value : null;
    }

    /**
     * Walks the file homes and keeps the result. Returns null when another
     * scan is already running. When the budget runs out the numbers are what
     * was counted so far and `complete` is false.
     *
     * @return array{bytes: int, files: int, users: int, counted_at: int, seconds: float, complete: bool}|null
     */
    public function scan(float $budgetSeconds = self::BUDGET_SECONDS, int $checkEvery = self::CHECK_EVERY): ?array
    {
        $lock = Cache::lock(self::LOCK_KEY, (int) ceil($budgetSeconds) + 60);

        if (! $lock->get()) {
            return null;
        }

        try {
            $started = microtime(true);
            $result = $this->walk($started + $budgetSeconds, max(1, $checkEvery));
            $result['counted_at'] = time();
            $result['seconds'] = round(microtime(true) - $started, 1);

            Cache::forever(self::CACHE_KEY, $result);

            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * Free and total space of the volume that holds the files. Read on every
     * render: it is one system call, not a walk.
     *
     * @return array{free: int, total: int}|null
     */
    public function volume(): ?array
    {
        $path = $this->storage->root();

        while (! is_dir($path) && dirname($path) !== $path) {
            $path = dirname($path);
        }

        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        return is_float($free) && is_float($total) && $total > 0 ? ['free' => (int) $free, 'total' => (int) $total] : null;
    }

    /**
     * @return array{bytes: int, files: int, users: int, complete: bool}
     */
    private function walk(float $deadline, int $checkEvery): array
    {
        $root = $this->storage->root();
        $bytes = 0;
        $files = 0;
        $users = 0;
        $seen = 0;
        $complete = true;

        if (! is_dir($root) || is_link($root)) {
            return ['bytes' => 0, 'files' => 0, 'users' => 0, 'complete' => true];
        }

        foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $home) {
            /** @var \SplFileInfo $home */
            if ($home->getFilename() === FileStorage::UPLOADS || $home->isLink() || ! $home->isDir()) {
                continue;
            }

            $before = $files;
            $stack = [$home->getPathname()];

            while ($stack !== []) {
                $directory = array_pop($stack);

                try {
                    $entries = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
                } catch (UnexpectedValueException) {
                    continue;
                }

                foreach ($entries as $entry) {
                    /** @var \SplFileInfo $entry */
                    if (++$seen % $checkEvery === 0 && microtime(true) > $deadline) {
                        $complete = false;

                        break 2;
                    }

                    if ($entry->isLink()) {
                        continue;
                    }

                    if ($entry->isDir()) {
                        $stack[] = $entry->getPathname();

                        continue;
                    }

                    if ($entry->isFile()) {
                        try {
                            $bytes += $entry->getSize();
                            $files++;
                        } catch (RuntimeException) {
                            // Deleted while it was being counted.
                        }
                    }
                }
            }

            if ($files > $before) {
                $users++;
            }

            if (! $complete) {
                break;
            }
        }

        return ['bytes' => $bytes, 'files' => $files, 'users' => $users, 'complete' => $complete];
    }
}
