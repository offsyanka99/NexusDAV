<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Whether a queue worker is running (Decision 8 of the Version 1.x plan:
 * an optional second container that runs `php artisan queue:work`).
 *
 * The worker writes a heartbeat row about once a minute (RecordWorkerHeartbeat).
 * A worker is running while its latest heartbeat is at most STALE_AFTER old,
 * stale when it is older, and never seen when there is no row. With more
 * than one worker, the newest heartbeat decides.
 */
class WorkerStatus
{
    public const RUNNING = 'running';

    public const STALE = 'stale';

    public const NEVER = 'never';

    public const HEARTBEAT_SECONDS = 60;

    public const STALE_AFTER_SECONDS = 300;

    /**
     * Rows of workers that have not written for this long are removed, so a
     * recreated container does not leave its old row for ever.
     */
    private const FORGET_AFTER_SECONDS = 86400;

    /**
     * @return array{state: string, worker: string|null, version: string|null, started_at: CarbonImmutable|null, beat_at: CarbonImmutable|null}
     */
    public function status(): array
    {
        $row = DB::table('worker_heartbeats')->orderByDesc('beat_at')->first();

        if ($row === null) {
            return ['state' => self::NEVER, 'worker' => null, 'version' => null, 'started_at' => null, 'beat_at' => null];
        }

        $beatAt = CarbonImmutable::parse((string) $row->beat_at);

        return [
            'state' => $beatAt->greaterThanOrEqualTo(CarbonImmutable::now()->subSeconds(self::STALE_AFTER_SECONDS)) ? self::RUNNING : self::STALE,
            'worker' => (string) $row->worker,
            'version' => $row->version === null ? null : (string) $row->version,
            'started_at' => CarbonImmutable::parse((string) $row->started_at),
            'beat_at' => $beatAt,
        ];
    }

    public function state(): string
    {
        return $this->status()['state'];
    }

    /**
     * Written by the worker process.
     */
    public function beat(string $worker, CarbonImmutable $startedAt): void
    {
        $now = CarbonImmutable::now();

        DB::table('worker_heartbeats')->upsert(
            [[
                'worker' => $worker,
                'version' => (string) config('nexusdav.version'),
                'started_at' => $startedAt,
                'beat_at' => $now,
            ]],
            ['worker'],
            ['version', 'started_at', 'beat_at'],
        );

        DB::table('worker_heartbeats')
            ->where('worker', '!=', $worker)
            ->where('beat_at', '<', $now->subSeconds(self::FORGET_AFTER_SECONDS))
            ->delete();
    }

    /**
     * Written by the probe job, on the row of the worker that ran it.
     */
    public function recordProbe(string $worker, string $token): void
    {
        $now = CarbonImmutable::now();

        DB::table('worker_heartbeats')->upsert(
            [[
                'worker' => $worker,
                'version' => (string) config('nexusdav.version'),
                'started_at' => $now,
                'beat_at' => $now,
                'last_probe' => $token,
            ]],
            ['worker'],
            ['beat_at', 'last_probe'],
        );
    }

    /**
     * The worker that ran the probe with this token, or null.
     */
    public function probedBy(string $token): ?string
    {
        $worker = DB::table('worker_heartbeats')->where('last_probe', $token)->value('worker');

        return $worker === null ? null : (string) $worker;
    }

    public static function workerName(): string
    {
        $name = gethostname();

        return is_string($name) && $name !== '' ? substr($name, 0, 100) : 'worker';
    }
}
