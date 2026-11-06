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
 * PostgreSQL a transaction-level advisory lock on the calendar or address
 * book id, so the owner's path and a sharee's path take the same lock. A
 * collection that does not exist yet stays keyed on its path.
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
     * First key of pg_advisory_xact_lock(int, int). Separate namespaces so a
     * calendar id, an address book id and a path hash do not share a lock.
     */
    private const PG_CALENDAR = 0x4E44;

    private const PG_ADDRESSBOOK = 0x4E45;

    private const PG_PATH = 0x4E46;

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

        $paths = $this->paths($server, $request);

        if ($paths === []) {
            return;
        }

        DB::beginTransaction();
        $this->open = true;

        $locks = $this->locks($paths);

        if (DB::connection()->getDriverName() === 'pgsql') {
            // Same wait as SQLite's busy_timeout, then an error instead of
            // waiting for ever behind a stuck request.
            DB::statement("SET LOCAL lock_timeout = '5s'");

            foreach ($locks as $lock) {
                DB::select('SELECT pg_advisory_xact_lock(?, ?)', [$lock['namespace'], $lock['key']]);
            }
        }

        // The ACL check before this point loaded the nodes, and sabre's tree
        // keeps them with the ETag they had then. Drop them so the
        // precondition check and the write read the rows under the lock.
        $server->tree->markDirty('');
    }

    /**
     * Collection paths the request writes, including a MOVE or COPY destination.
     *
     * @return list<string>
     */
    private function paths(Server $server, RequestInterface $request): array
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

        return array_keys($collections);
    }

    /**
     * One lock per collection id, in one sorted order, so two requests that
     * lock the same pair cannot deadlock. A missing row keeps the path.
     *
     * @param  list<string>  $paths
     * @return list<array{namespace: int, key: int}>
     */
    private function locks(array $paths): array
    {
        $locks = [];

        foreach ($paths as $path) {
            $lock = $this->resolve($path);
            $locks[$lock['namespace'].':'.$lock['key']] = $lock;
        }

        ksort($locks, SORT_STRING);

        return array_values($locks);
    }

    /**
     * @return array{namespace: int, key: int}
     */
    private function resolve(string $collection): array
    {
        [$root, $user, $uri] = explode('/', $collection, 3);
        $principal = 'principals/'.$user;

        if ($root === 'calendars') {
            $id = DB::table('calendarinstances')->where('principaluri', $principal)->where('uri', $uri)->value('calendarid');

            if ($id !== null) {
                return ['namespace' => self::PG_CALENDAR, 'key' => $this->idKey((int) $id)];
            }
        } else {
            $id = DB::table('addressbooks')->where('principaluri', $principal)->where('uri', $uri)->value('id');

            if ($id === null) {
                $id = DB::table('addressbookinstances')->where('principaluri', $principal)->where('uri', $uri)->value('addressbookid');
            }

            if ($id !== null) {
                return ['namespace' => self::PG_ADDRESSBOOK, 'key' => $this->idKey((int) $id)];
            }
        }

        return ['namespace' => self::PG_PATH, 'key' => $this->hashKey($collection)];
    }

    /**
     * pg_advisory_xact_lock's second argument is a signed 32-bit int.
     */
    private function idKey(int $id): int
    {
        if ($id >= 1 && $id <= 0x7FFFFFFF) {
            return $id;
        }

        return $this->hashKey('id:'.$id);
    }

    private function hashKey(string $value): int
    {
        $hash = crc32($value);

        return $hash > 0x7FFFFFFF ? $hash - 0x100000000 : $hash;
    }
}
