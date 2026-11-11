<?php

namespace App\Services;

use App\Models\User;
use App\Services\Dav\FileStorage;
use App\Services\Push\PushKeys;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Returns the server to a fresh install, keeping the admin who runs it
 * (PR 46 of the Version 1.x.x plan).
 *
 * Deleted: every other user, all calendars, address books, their items,
 * shares and sync history, locks and properties, every app password, push
 * subscriptions and keys, diagnostics, saved settings, queued and failed
 * jobs, every session but the one that runs the reset, the cache, and every
 * file. Kept: the admin's account and password, the schema, APP_KEY, the
 * environment and the worker heartbeats. The admin gets fresh default
 * collections, like a new user.
 *
 * Order: a flag closes the server (ResetFlag: DAV and the panel answer 503,
 * the worker waits); the file storage is moved out of service; the database
 * part runs in one transaction behind the locks DAV writes take; then the
 * moved files are deleted and the flag is removed. A failing database part
 * puts the files back and changes nothing.
 */
final class FactoryReset
{
    /**
     * Emptied, apart from the rows of the admin and the session named in run().
     */
    public const RESET_TABLES = [
        'users', 'principals', 'sessions',
        'app_passwords',
        'calendars', 'calendarinstances', 'calendarobjects', 'calendarchanges', 'calendarsubscriptions', 'schedulingobjects',
        'addressbooks', 'addressbookinstances', 'cards', 'addressbookchanges',
        'locks', 'propertystorage',
        'push_subscriptions', 'push_outbox',
        'diagnostic_events',
        'settings',
        'jobs', 'job_batches', 'failed_jobs',
        'cache', 'cache_locks',
    ];

    public const KEPT_TABLES = ['migrations', 'worker_heartbeats'];

    /**
     * Tables whose rows DAV clients write. Locking them waits for a write
     * that started before the flag.
     */
    private const WRITER_TABLES = [
        'calendars', 'calendarinstances', 'calendarobjects', 'calendarchanges',
        'addressbooks', 'addressbookinstances', 'cards', 'addressbookchanges',
        'locks', 'propertystorage',
    ];

    public function __construct(
        private ResetFlag $flag,
        private FileStorage $storage,
        private UserService $users,
        private SettingsService $settings,
    ) {}

    public function run(User $admin, string $keepSessionId): FactoryResetResult
    {
        if (! $admin->exists || ! $admin->is_admin || ! $admin->is_active) {
            throw new InvalidArgumentException('Only an active admin can reset the server.');
        }

        $counts = $this->counts($admin);
        $this->flag->set();

        try {
            $quarantine = $this->moveFilesAway();

            try {
                DB::transaction(function () use ($admin, $keepSessionId): void {
                    $this->lockWriters();
                    $this->deleteRows($admin, $keepSessionId);
                    DB::table('users')->where('id', $admin->getKey())->update(['collections_provisioned' => false]);
                    $this->users->backfillHomeCollections();
                });
            } catch (Throwable $exception) {
                $this->putFilesBack($quarantine);

                throw $exception;
            }

            $this->settings->forget();
            $this->settings->apply();
            app()->forgetInstance(PushKeys::class);

            $bytes = $this->deleteMovedFiles($quarantine);
        } finally {
            $this->flag->clear();
        }

        $result = new FactoryResetResult($counts['users'], $counts['calendars'], $counts['address_books'], $counts['items'], $bytes);

        Log::warning('Factory reset', [
            'admin' => $admin->username,
            'users' => $result->users,
            'calendars' => $result->calendars,
            'address_books' => $result->addressBooks,
            'items' => $result->items,
            'file_bytes' => $result->fileBytes,
        ]);

        return $result;
    }

    /**
     * What a reset would delete, for the confirmation and the log line.
     *
     * @return array{users: int, calendars: int, address_books: int, items: int}
     */
    public function counts(User $admin): array
    {
        return [
            'users' => DB::table('users')->where('id', '!=', $admin->getKey())->count(),
            'calendars' => DB::table('calendars')->count(),
            'address_books' => DB::table('addressbooks')->count(),
            'items' => DB::table('calendarobjects')->count() + DB::table('cards')->count(),
        ];
    }

    /**
     * SQLite took its one write lock at BEGIN (transaction mode IMMEDIATE).
     * On PostgreSQL an EXCLUSIVE table lock waits for every open write to
     * these tables and lets plain reads through.
     */
    private function lockWriters(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("SET LOCAL lock_timeout = '10s'");
        DB::statement('LOCK TABLE '.implode(', ', self::WRITER_TABLES).' IN EXCLUSIVE MODE');
    }

    private function deleteRows(User $admin, string $keepSessionId): void
    {
        $principal = $admin->principal_uri;

        foreach (self::RESET_TABLES as $table) {
            $query = DB::table($table);

            match ($table) {
                'users' => $query->where('id', '!=', $admin->getKey()),
                'principals' => $query->where('uri', '!=', $principal),
                'sessions' => $query->where('id', '!=', $keepSessionId),
                default => null,
            };

            $query->delete();
        }
    }

    /**
     * Renames the storage root, so no file is served from here on, also not
     * to the admin, whose username stays. Null when there are no files.
     */
    private function moveFilesAway(): ?string
    {
        $root = $this->storage->root();
        clearstatcache(true, $root);

        if (! is_link($root) && ! file_exists($root)) {
            return null;
        }

        $quarantine = dirname($root).'/.files-reset-'.bin2hex(random_bytes(6));

        if (! @rename($root, $quarantine)) {
            throw new RuntimeException('Could not move the files out of the way.');
        }

        return $quarantine;
    }

    private function putFilesBack(?string $quarantine): void
    {
        if ($quarantine === null) {
            return;
        }

        $root = $this->storage->root();
        clearstatcache(true, $root);

        if (! file_exists($root) && @rename($quarantine, $root)) {
            return;
        }

        Log::error('Factory reset failed, and its files could not be put back', ['path' => $quarantine]);
    }

    /**
     * Deletes the moved files and returns their size. An error is logged:
     * the files are already out of service.
     */
    private function deleteMovedFiles(?string $quarantine): int
    {
        if ($quarantine === null) {
            return 0;
        }

        $bytes = 0;

        try {
            if (is_dir($quarantine) && ! is_link($quarantine)) {
                $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($quarantine, FilesystemIterator::SKIP_DOTS));

                foreach ($files as $file) {
                    /** @var SplFileInfo $file */
                    if ($file->isFile() && ! $file->isLink()) {
                        $bytes += (int) $file->getSize();
                    }
                }
            }

            $this->storage->deleteTree($quarantine);
        } catch (Throwable $exception) {
            Log::error('Factory reset could not delete all files', ['path' => $quarantine, 'exception' => $exception]);
        }

        return $bytes;
    }
}
