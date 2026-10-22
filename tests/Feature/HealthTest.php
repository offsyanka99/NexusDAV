<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_is_ok_when_the_database_is_up(): void
    {
        $response = $this->get('/health');

        $response->assertOk();
        $response->assertExactJson(['status' => 'ok']);

        $body = (string) $response->getContent();
        $this->assertStringNotContainsStringIgnoringCase('exception', $body);
        $this->assertStringNotContainsStringIgnoringCase('sql', $body);
        $this->assertStringNotContainsString('vendor', $body);
    }

    public function test_health_hides_database_errors(): void
    {
        $original = config('database.default');

        config([
            'database.connections.down' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'port' => 1,
                'database' => 'nexusdav',
                'username' => 'nexusdav',
                'password' => 'super-secret-db-password',
            ],
            'database.default' => 'down',
        ]);

        DB::purge('down');

        try {
            $response = $this->get('/health');
        } finally {
            config(['database.default' => $original]);
            DB::purge('down');
        }

        $response->assertStatus(503);
        $response->assertExactJson(['status' => 'unavailable']);

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('super-secret-db-password', $body);
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('Connection refused', $body);
        $this->assertStringNotContainsString('vendor', $body);
    }

    public function test_forwarded_proto_and_host_are_honored(): void
    {
        $this->call('GET', '/health', [], [], [], [
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'dav.example.com',
            'REMOTE_ADDR' => '10.1.2.3',
        ]);

        $this->assertTrue(request()->isSecure());
        $this->assertSame('dav.example.com', request()->getHost());
    }
}
