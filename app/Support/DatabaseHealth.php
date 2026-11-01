<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The one database check behind GET /health and the Dashboard, so the two
 * cannot disagree. It reports up or down and the database type, never a
 * host, path, or error text.
 */
class DatabaseHealth
{
    public function isUp(): bool
    {
        try {
            DB::connection()->select('select 1');
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    public function label(): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => 'PostgreSQL',
            'sqlite' => 'SQLite',
            default => 'Database',
        };
    }
}
