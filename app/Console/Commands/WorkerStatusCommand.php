<?php

namespace App\Console\Commands;

use App\Jobs\WorkerProbe;
use App\Services\WorkerStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Prints whether a queue worker is running. Exits 0 when one is, so it also
 * serves as the worker container's health check. --probe also sends a job
 * through the queue and waits for a worker to run it.
 */
class WorkerStatusCommand extends Command
{
    protected $signature = 'nexusdav:worker-status
        {--probe : Send a probe job and wait until a worker has run it}
        {--wait=30 : Seconds to wait for the probe}';

    protected $description = 'Show whether a queue worker is running, and optionally check that it runs jobs';

    public function handle(WorkerStatus $workers): int
    {
        $status = $workers->status();

        $line = match ($status['state']) {
            WorkerStatus::RUNNING => 'Worker running: '.$status['worker'].', last heartbeat '.$status['beat_at']?->diffForHumans().'.',
            WorkerStatus::STALE => 'Worker stale: no heartbeat since '.$status['beat_at']?->toIso8601String().' ('.$status['worker'].').',
            default => 'No worker has been seen.',
        };

        $this->line($line);

        if (! $this->option('probe')) {
            return $status['state'] === WorkerStatus::RUNNING ? self::SUCCESS : self::FAILURE;
        }

        $token = Str::random(32);
        $started = microtime(true);
        WorkerProbe::dispatch($token);

        $deadline = $started + max(1, (int) $this->option('wait'));

        do {
            $worker = $workers->probedBy($token);

            if ($worker !== null) {
                $this->line(sprintf('Probe job ran on %s in %.1f s.', $worker, microtime(true) - $started));

                return self::SUCCESS;
            }

            usleep(250_000);
        } while (microtime(true) < $deadline);

        $this->error('No worker ran the probe job within '.(int) $this->option('wait').' seconds.');

        return self::FAILURE;
    }
}
