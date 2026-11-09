<?php

namespace App\Jobs;

use App\Services\WorkerStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Proves that a worker takes jobs from the queue: it stores its token on the
 * row of the worker that ran it. Sent by `nexusdav:worker-status --probe`.
 */
class WorkerProbe implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public string $token) {}

    public function handle(WorkerStatus $status): void
    {
        $worker = WorkerStatus::workerName();
        $status->recordProbe($worker, $this->token);

        Log::info('Worker probe ran', ['probe' => $this->token, 'worker' => $worker]);
    }
}
