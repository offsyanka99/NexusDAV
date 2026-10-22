<?php

namespace App\Support;

use RuntimeException;

final class EnvironmentChecker
{
    /**
     * Stop startup when a variable required before migrations is missing.
     *
     * @param  array<string, string|null>  $environment
     */
    public function assertBootable(array $environment): void
    {
        $missing = [];

        foreach (['APP_URL', 'DB_CONNECTION'] as $name) {
            if ($this->blank($environment[$name] ?? null)) {
                $missing[] = $name;
            }
        }

        $connection = strtolower(trim((string) ($environment['DB_CONNECTION'] ?? '')));

        if ($connection === 'pgsql') {
            foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $name) {
                if ($this->blank($environment[$name] ?? null)) {
                    $missing[] = $name;
                }
            }
        } elseif ($connection === 'sqlite') {
            if ($this->blank($environment['DB_DATABASE'] ?? null)) {
                $missing[] = 'DB_DATABASE';
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'Missing required environment variable: '.implode(', ', $missing).'.'
            );
        }

        if (! in_array($connection, ['sqlite', 'pgsql'], true)) {
            throw new RuntimeException('DB_CONNECTION must be sqlite or pgsql.');
        }
    }

    /**
     * Admin credentials are required only when no admin account exists yet.
     *
     * @param  array<string, string|null>  $environment
     */
    public function assertAdminCredentials(array $environment, bool $adminExists): void
    {
        if ($adminExists) {
            return;
        }

        $missing = [];

        foreach (['ADMIN_EMAIL', 'ADMIN_PASSWORD'] as $name) {
            if ($this->blank($environment[$name] ?? null)) {
                $missing[] = $name;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'Missing required environment variable: '.implode(', ', $missing).'.'
            );
        }
    }

    private function blank(mixed $value): bool
    {
        return ! is_string($value) || trim($value) === '';
    }
}
