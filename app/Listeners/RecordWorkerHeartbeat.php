<?php

namespace App\Listeners;

use App\Services\WorkerStatus;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The queue worker's heartbeat: once when it starts, then on its loop at
 * most once a minute. An idle worker loops every few seconds (--sleep), so
 * the heartbeat keeps coming while there is nothing to do. A failure to
 * write it is logged and never stops the worker.
 */
class RecordWorkerHeartbeat
{
    private static ?CarbonImmutable $startedAt = null;

    private static ?float $lastBeat = null;

    public function handle(WorkerStarting|Looping $event): void
    {
        $now = microtime(true);

        if ($event instanceof WorkerStarting || self::$startedAt === null) {
            self::$startedAt = CarbonImmutable::now();
            self::$lastBeat = null;
        }

        if (self::$lastBeat !== null && $now - self::$lastBeat < WorkerStatus::HEARTBEAT_SECONDS) {
            return;
        }

        self::$lastBeat = $now;

        try {
            app(WorkerStatus::class)->beat(WorkerStatus::workerName(), self::$startedAt);
        } catch (Throwable $exception) {
            Log::warning('Could not write the worker heartbeat', ['exception' => $exception]);
        }
    }

    public static function reset(): void
    {
        self::$startedAt = null;
        self::$lastBeat = null;
    }
}
