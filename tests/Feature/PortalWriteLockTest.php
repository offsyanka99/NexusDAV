<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Dav\BusyDatabase;
use App\Services\Dav\DavWriteLock;
use App\Services\Portal\CollectionRef;
use App\Services\Portal\WritePipeline;
use App\Services\UserService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LogicException;
use ReflectionClass;
use Tests\TestCase;
use Throwable;

/**
 * PR 50a, step 3: a portal write holds the lock a DAV write to the same
 * collection takes. On PostgreSQL that is the collection's advisory lock;
 * on SQLite the database write lock taken at BEGIN (IMMEDIATE), so a second
 * writer waits and then fails busy, as a DAV write does. Runs on its own
 * database so a second connection sees the locks.
 */
class PortalWriteLockTest extends TestCase
{
    private string $previousDefault;

    private string $driver;

    private ?string $file = null;

    private ?string $database = null;

    private User $ada;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousDefault = (string) config('database.default');
        $base = config('database.connections.'.$this->previousDefault);
        $this->driver = (string) $base['driver'];

        if ($this->driver === 'sqlite') {
            $this->file = tempnam(sys_get_temp_dir(), 'nexusdav-portal-lock-').'.sqlite';
            touch($this->file);
            $base['database'] = $this->file;
        } else {
            $this->database = $base['database'].'_portal_lock';
            DB::statement('DROP DATABASE IF EXISTS "'.$this->database.'" WITH (FORCE)');
            DB::statement('CREATE DATABASE "'.$this->database.'"');
            $base['database'] = $this->database;
        }

        config([
            'database.connections.portal_lock' => $base,
            'database.connections.portal_lock_other' => [...$base, 'busy_timeout' => 200],
            'database.default' => 'portal_lock',
        ]);
        Artisan::call('migrate', ['--database' => 'portal_lock', '--force' => true]);

        $this->ada = app(UserService::class)->create(['username' => 'ada', 'display_name' => 'Ada', 'email' => 'ada@example.com']);
    }

    protected function tearDown(): void
    {
        DB::purge('portal_lock_other');
        DB::purge('portal_lock');
        config(['database.default' => $this->previousDefault]);

        if ($this->file !== null) {
            @unlink($this->file);
            @unlink(substr($this->file, 0, -strlen('.sqlite')));
            @unlink($this->file.'-wal');
            @unlink($this->file.'-shm');
        }

        if ($this->database !== null) {
            DB::connection($this->previousDefault)->statement('DROP DATABASE IF EXISTS "'.$this->database.'" WITH (FORCE)');
        }

        parent::tearDown();
    }

    public function test_a_collection_lock_needs_an_open_transaction(): void
    {
        $this->expectException(LogicException::class);

        DavWriteLock::lockCalendar(1);
    }

    public function test_a_store_write_holds_the_lock_a_dav_write_to_the_collection_takes(): void
    {
        $calendarId = (int) DB::table('calendarinstances')->where('principaluri', 'principals/ada')->value('calendarid');
        $bookId = (int) DB::table('addressbooks')->where('principaluri', 'principals/ada')->value('id');

        // The DAV write to the owner's path resolves to the same lock.
        $this->assertSame(['namespace' => $this->constant('PG_CALENDAR'), 'key' => $calendarId], $this->davLock('calendars/ada/default'));
        $this->assertSame(['namespace' => $this->constant('PG_ADDRESSBOOK'), 'key' => $bookId], $this->davLock('addressbooks/ada/default'));

        foreach ([[CollectionRef::CALENDAR, $calendarId, 'PG_CALENDAR'], [CollectionRef::ADDRESS_BOOK, $bookId, 'PG_ADDRESSBOOK']] as [$type, $id, $namespace]) {
            $seen = app(WritePipeline::class)->write($this->ada, $type, $id, fn (): ?bool => $this->secondWriterGetsIn($namespace, $id));

            $this->assertFalse($seen, 'A second writer got the lock of '.$type.' '.$id.' while the store held it.');
        }

        // Released with the transaction.
        $this->assertTrue($this->secondWriterGetsIn('PG_CALENDAR', $calendarId));
    }

    /**
     * Whether a second connection can start a write to the collection now.
     */
    private function secondWriterGetsIn(string $namespace, int $id): bool
    {
        $other = DB::connection('portal_lock_other');

        if ($this->driver === 'sqlite') {
            try {
                $other->beginTransaction();
                $other->rollBack();

                return true;
            } catch (Throwable $exception) {
                $this->assertTrue(BusyDatabase::caused($exception), $exception->getMessage());

                return false;
            }
        }

        $pdo = $other->getPdo();
        $pdo->beginTransaction();

        try {
            $statement = $pdo->prepare('SELECT pg_try_advisory_xact_lock(?, ?)');
            $statement->execute([$this->constant($namespace), $id]);

            return (bool) $statement->fetchColumn();
        } finally {
            $pdo->rollBack();
        }
    }

    private function constant(string $name): int
    {
        return (int) (new ReflectionClass(DavWriteLock::class))->getConstant($name);
    }

    /**
     * @return array{namespace: int, key: int}
     */
    private function davLock(string $collection): array
    {
        $method = (new ReflectionClass(DavWriteLock::class))->getMethod('resolve');

        return $method->invoke(new DavWriteLock, $collection);
    }
}
