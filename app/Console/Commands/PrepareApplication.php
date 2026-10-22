<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\EnvironmentChecker;
use App\Support\FirstAdminCreator;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use RuntimeException;

class PrepareApplication extends Command
{
    protected $signature = 'nexusdav:prepare';

    protected $description = 'Validate the environment, migrate, and create the first admin if needed';

    public function handle(EnvironmentChecker $checker, FirstAdminCreator $admins): int
    {
        $environment = $this->environment();

        try {
            $checker->assertBootable($environment);
            $this->ensureSqliteDirectory();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $migrated = $this->call('migrate', ['--force' => true]);

        if ($migrated !== self::SUCCESS) {
            return $migrated;
        }

        try {
            $adminExists = User::query()->where('is_admin', true)->exists();
            $checker->assertAdminCredentials($environment, $adminExists);

            if (! $adminExists) {
                $admin = $admins->createIfMissing(
                    $environment['ADMIN_EMAIL'] ?? null,
                    $environment['ADMIN_PASSWORD'] ?? null,
                );

                if ($admin->wasRecentlyCreated) {
                    $this->info('Created the first admin account.');
                }
            }
        } catch (QueryException $exception) {
            $this->error($this->queryFailureMessage($exception));

            return self::FAILURE;
        } catch (RuntimeException|InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, string|null>
     */
    private function environment(): array
    {
        $names = [
            'APP_URL',
            'DB_CONNECTION',
            'DB_HOST',
            'DB_PORT',
            'DB_DATABASE',
            'DB_USERNAME',
            'DB_PASSWORD',
            'ADMIN_EMAIL',
            'ADMIN_PASSWORD',
        ];

        $environment = [];

        foreach ($names as $name) {
            $environment[$name] = $this->read($name);
        }

        return $environment;
    }

    private function queryFailureMessage(QueryException $exception): string
    {
        $detail = $exception->errorInfo[2] ?? '';

        if (is_string($detail) && preg_match('/constraint "([^"]+)"/', $detail, $matches) === 1) {
            return "Could not create the first admin because of constraint {$matches[1]}.";
        }

        if (is_string($detail) && preg_match('/UNIQUE constraint failed: ([\w.]+)/', $detail, $matches) === 1) {
            return "Could not create the first admin because of unique constraint on {$matches[1]}.";
        }

        return 'Could not create the first admin because the database rejected the insert.';
    }

    private function read(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        if (! is_string($value)) {
            return null;
        }

        return $value;
    }

    private function ensureSqliteDirectory(): void
    {
        if (config('database.default') !== 'sqlite') {
            return;
        }

        $database = config('database.connections.sqlite.database');

        if (! is_string($database) || $database === '' || $database === ':memory:') {
            return;
        }

        $directory = dirname($database);

        if (is_dir($directory)) {
            return;
        }

        if (! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create the SQLite directory [{$directory}].");
        }
    }
}
