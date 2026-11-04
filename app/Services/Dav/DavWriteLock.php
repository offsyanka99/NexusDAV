<?php

namespace App\Services\Dav;

use Illuminate\Support\Facades\DB;
use Sabre\DAV\Server;
use Sabre\HTTP\RequestInterface;
use Throwable;

/**
 * One database transaction around a write to a calendar or an address book,
 * from just after authentication and the privilege check until the answer is
 * built (PR 16 of the Version 1.x plan).
 *
 * sabre checks If-Match and If-None-Match after beforeMethod and then writes,
 * so without this two clients holding the same ETag both pass the check and
 * the second silently replaces the first, and two creates of the same new URI
 * end in a 500 from the unique index. Writes to the same collection now wait
 * for each other: SQLite takes the database write lock at BEGIN (IMMEDIATE),
 * PostgreSQL a transaction-level advisory lock per collection.
 */
final class DavWriteLock
{
    public const METHODS = ['PUT', 'DELETE', 'MOVE', 'COPY', 'PROPPATCH', 'MKCOL', 'MKCALENDAR'];

    /**
     * Runs after the auth plugin (10), the ACL check (20) and the service
     * switches (100), so a refused request never takes the lock.
     */
    private const PRIORITY = 110;

    /**
     * Arbitrary first key of pg_advisory_xact_lock(int, int), so these locks
     * do not collide with any other advisory lock user.
     */
    private const PG_NAMESPACE = 0x4E44;

    private bool $open = false;

    private bool $failed = false;

    public function attach(Server $server): void
    {
        $server->on('beforeMethod:*', function (RequestInterface $request) use ($server): void {
            $this->begin($server, $request);
        }, self::PRIORITY);

        $server->on('exception', function (): void {
            $this->failed = true;
        });
    }

    /**
     * Commits, or rolls back when sabre reported an exception. Called once the
     * request is answered, also for a 412: sabre answers a failed precondition
     * without afterMethod.
     */
    public function finish(): void
    {
        if (! $this->open) {
            return;
        }

        $this->open = false;

        if ($this->failed) {
            DB::rollBack();

            return;
        }

        try {
            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    public function abort(): void
    {
        $this->failed = true;
        $this->finish();
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    private function begin(Server $server, RequestInterface $request): void
    {
        if ($this->open || ! in_array(strtoupper($request->getMethod()), self::METHODS, true)) {
            return;
        }

        $collections = $this->collections($server, $request);

        if ($collections === []) {
            return;
        }

        DB::beginTransaction();
        $this->open = true;

        if (DB::connection()->getDriverName() === 'pgsql') {
            // Same wait as SQLite's busy_timeout, then an error instead of
            // waiting for ever behind a stuck request.
            DB::statement("SET LOCAL lock_timeout = '5s'");

            foreach ($collections as $collection) {
                DB::select('SELECT pg_advisory_xact_lock(?, ?)', [self::PG_NAMESPACE, $this->key($collection)]);
            }
        }

        // The ACL check before this point loaded the nodes, and sabre's tree
        // keeps them with the ETag they had then. Drop them so the
        // precondition check and the write read the rows under the lock.
        $server->tree->markDirty('');
    }

    /**
     * The calendars and address books the request writes to, sorted so two
     * requests that lock the same pair (MOVE, COPY) take them in one order.
     *
     * @return list<string>
     */
    private function collections(Server $server, RequestInterface $request): array
    {
        $paths = [$request->getPath()];
        $destination = $request->getHeader('Destination');

        if (is_string($destination) && $destination !== '') {
            try {
                $paths[] = $server->calculateUri($destination);
            } catch (Throwable) {
                // sabre answers a bad Destination itself.
            }
        }

        $collections = [];

        foreach ($paths as $path) {
            $segments = explode('/', trim($path, '/'));

            if (count($segments) >= 3 && in_array($segments[0], ['calendars', 'addressbooks'], true)) {
                $collections[$segments[0].'/'.$segments[1].'/'.$segments[2]] = true;
            }
        }

        $collections = array_keys($collections);
        sort($collections);

        return $collections;
    }

    private function key(string $collection): int
    {
        $hash = crc32($collection);

        return $hash > 0x7FFFFFFF ? $hash - 0x100000000 : $hash;
    }
}
