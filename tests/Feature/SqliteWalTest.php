<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SqliteWalTest extends TestCase
{
    public function test_sqlite_file_uses_wal_and_a_busy_timeout(): void
    {
        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('SQLite only.');
        }

        $path = sys_get_temp_dir().'/nexusdav-wal-'.bin2hex(random_bytes(6)).'.sqlite';
        touch($path);

        try {
            config([
                'database.connections.sqlite_wal_probe' => array_merge(
                    config('database.connections.sqlite'),
                    ['database' => $path],
                ),
            ]);

            $pdo = DB::connection('sqlite_wal_probe')->getPdo();
            $journal = $pdo->query('pragma journal_mode')->fetchColumn();
            $timeout = $pdo->query('pragma busy_timeout')->fetchColumn();

            $this->assertSame('wal', strtolower((string) $journal));
            $this->assertSame(5000, (int) $timeout);
        } finally {
            DB::purge('sqlite_wal_probe');
            @unlink($path);
            @unlink($path.'-wal');
            @unlink($path.'-shm');
        }
    }
}
