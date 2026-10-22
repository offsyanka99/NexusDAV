<?php

namespace Tests\Unit;

use App\Support\EnvironmentChecker;
use App\Support\FirstAdminCreator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class EnvironmentCheckerTest extends TestCase
{
    public function test_missing_app_url_names_the_variable(): void
    {
        $checker = new EnvironmentChecker;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_URL');

        $checker->assertBootable([
            'APP_URL' => '',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => '/data/database/nexusdav.sqlite',
        ]);
    }

    public function test_sqlite_requires_the_database_path(): void
    {
        $checker = new EnvironmentChecker;

        try {
            $checker->assertBootable([
                'APP_URL' => 'http://localhost:8080',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => '   ',
            ]);
            $this->fail('Expected the missing DB_DATABASE to stop startup.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('DB_DATABASE', $exception->getMessage());
        }
    }

    public function test_pgsql_requires_each_connection_variable(): void
    {
        $checker = new EnvironmentChecker;

        try {
            $checker->assertBootable([
                'APP_URL' => 'http://localhost:8080',
                'DB_CONNECTION' => 'pgsql',
                'DB_HOST' => 'postgres',
                'DB_PORT' => '5432',
                'DB_DATABASE' => 'nexusdav',
                'DB_USERNAME' => 'nexusdav',
                'DB_PASSWORD' => '',
            ]);
            $this->fail('Expected the missing DB_PASSWORD to stop startup.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('DB_PASSWORD', $exception->getMessage());
        }
    }

    public function test_mysql_is_rejected(): void
    {
        $checker = new EnvironmentChecker;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DB_CONNECTION');

        $checker->assertBootable([
            'APP_URL' => 'http://localhost:8080',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'nexusdav',
        ]);
    }

    public function test_admin_credentials_are_required_only_when_no_admin_exists(): void
    {
        $checker = new EnvironmentChecker;
        $environment = [
            'ADMIN_EMAIL' => '',
            'ADMIN_PASSWORD' => null,
        ];

        $checker->assertAdminCredentials($environment, adminExists: true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ADMIN_EMAIL');

        $checker->assertAdminCredentials($environment, adminExists: false);
    }

    public function test_username_is_derived_from_the_email_local_part(): void
    {
        $this->assertSame('adminusertag', FirstAdminCreator::usernameFromEmail('Admin.User+tag@example.com'));
        $this->assertSame('admin', FirstAdminCreator::usernameFromEmail('_admin@example.com'));
        $this->assertSame('a_b-c', FirstAdminCreator::usernameFromEmail('a__b--c@example.com'));
        $this->assertSame('Admin.User+tag', FirstAdminCreator::displayNameFromEmail('Admin.User+tag@example.com'));
    }

    public function test_username_derivation_stops_when_nothing_valid_remains(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ADMIN_EMAIL');

        FirstAdminCreator::usernameFromEmail('+@example.com');
    }
}
