<?php

namespace App\Services\Dav;

use Illuminate\Database\ConcurrencyErrorDetector;
use PDOException;
use Throwable;

/**
 * Recognises a database that refused a statement only because another
 * request held a lock, so trying again later can succeed (PR 17 of the
 * Version 1.x plan): SQLite "database is locked" after busy_timeout, and
 * PostgreSQL serialization failures, deadlocks and lock timeouts.
 */
final class BusyDatabase
{
    /**
     * 40001 serialization failure, 40P01 deadlock, 55P03 lock_timeout.
     */
    private const SQLSTATES = ['40001', '40P01', '55P03'];

    public static function caused(Throwable $exception): bool
    {
        $detector = new ConcurrencyErrorDetector;

        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof PDOException && in_array((string) $current->getCode(), self::SQLSTATES, true)) {
                return true;
            }

            if ($detector->causedByConcurrencyError($current)) {
                return true;
            }
        }

        return false;
    }
}
