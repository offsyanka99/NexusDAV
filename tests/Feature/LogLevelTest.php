<?php

namespace Tests\Feature;

use Illuminate\Support\Env;
use Illuminate\Support\Facades\Log;
use ReflectionProperty;
use Tests\TestCase;

class LogLevelTest extends TestCase
{
    public function test_blank_log_level_uses_the_debug_default(): void
    {
        $this->bootWithLogLevel('');

        try {
            $this->assertSame('debug', config('logging.channels.single.level'));
            Log::channel('single')->info('blank log level is ignored');
        } finally {
            $this->bootWithLogLevel(null);
        }
    }

    public function test_set_log_level_is_kept(): void
    {
        $this->bootWithLogLevel('error');

        try {
            $this->assertSame('error', config('logging.channels.single.level'));
        } finally {
            $this->bootWithLogLevel(null);
        }
    }

    private function bootWithLogLevel(?string $level): void
    {
        if ($level === null) {
            putenv('LOG_LEVEL');
            unset($_ENV['LOG_LEVEL'], $_SERVER['LOG_LEVEL']);
        } else {
            putenv('LOG_LEVEL='.$level);
            $_ENV['LOG_LEVEL'] = $level;
            $_SERVER['LOG_LEVEL'] = $level;
        }

        $repository = new ReflectionProperty(Env::class, 'repository');
        $repository->setValue(null, null);

        $this->refreshApplication();
    }
}
