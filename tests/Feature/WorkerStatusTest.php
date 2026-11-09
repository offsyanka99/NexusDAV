<?php

namespace Tests\Feature;

use App\Jobs\WorkerProbe;
use App\Listeners\RecordWorkerHeartbeat;
use App\Services\WorkerStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * PR 34: the queue worker's heartbeat, WorkerStatus, and the status command.
 */
class WorkerStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RecordWorkerHeartbeat::reset();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        RecordWorkerHeartbeat::reset();

        parent::tearDown();
    }

    public function test_never_seen_running_then_stale(): void
    {
        $status = app(WorkerStatus::class);
        CarbonImmutable::setTestNow('2026-11-09 12:00:00');

        $this->assertSame(WorkerStatus::NEVER, $status->state());
        $this->assertNull($status->status()['beat_at']);

        $status->beat('worker-a', CarbonImmutable::now());

        $this->assertSame(WorkerStatus::RUNNING, $status->state());
        $this->assertSame('worker-a', $status->status()['worker']);

        CarbonImmutable::setTestNow('2026-11-09 12:05:00');
        $this->assertSame(WorkerStatus::RUNNING, $status->state(), 'Exactly five minutes is still running.');

        CarbonImmutable::setTestNow('2026-11-09 12:05:01');
        $this->assertSame(WorkerStatus::STALE, $status->state());

        $status->beat('worker-a', CarbonImmutable::parse('2026-11-09 12:00:00'));
        $this->assertSame(WorkerStatus::RUNNING, $status->state());
        $this->assertSame(1, DB::table('worker_heartbeats')->count());
    }

    public function test_the_newest_of_several_workers_decides_and_old_rows_are_forgotten(): void
    {
        $status = app(WorkerStatus::class);

        CarbonImmutable::setTestNow('2026-11-07 12:00:00');
        $status->beat('gone', CarbonImmutable::now());
        CarbonImmutable::setTestNow('2026-11-09 11:00:00');
        $status->beat('old', CarbonImmutable::now());
        CarbonImmutable::setTestNow('2026-11-09 12:00:00');
        $status->beat('new', CarbonImmutable::now());

        $this->assertSame(WorkerStatus::RUNNING, $status->state());
        $this->assertSame('new', $status->status()['worker']);
        $this->assertSame(['new', 'old'], DB::table('worker_heartbeats')->orderBy('worker')->pluck('worker')->all());
    }

    public function test_the_worker_beats_when_it_starts_and_then_at_most_once_a_minute(): void
    {
        CarbonImmutable::setTestNow('2026-11-09 12:00:00');
        $options = new WorkerOptions;

        Event::dispatch(new WorkerStarting('database', 'default', $options));
        $first = DB::table('worker_heartbeats')->value('beat_at');
        $this->assertNotNull($first);
        $this->assertSame(WorkerStatus::workerName(), DB::table('worker_heartbeats')->value('worker'));

        CarbonImmutable::setTestNow('2026-11-09 12:00:30');
        Event::dispatch(new Looping('database', 'default', $options));
        $this->assertSame($first, DB::table('worker_heartbeats')->value('beat_at'), 'No second write within a minute.');
    }

    public function test_the_heartbeat_listener_never_lets_a_failing_write_stop_the_worker(): void
    {
        DB::statement('DROP TABLE worker_heartbeats');

        (new RecordWorkerHeartbeat)->handle(new Looping('database', 'default', new WorkerOptions));

        $this->addToAssertionCount(1);
    }

    public function test_the_looping_listener_does_not_pause_the_worker(): void
    {
        $result = Event::until(new Looping('database', 'default', new WorkerOptions));

        $this->assertNotFalse($result, 'A listener returning false would pause the worker.');
    }

    public function test_the_status_command_exits_zero_only_while_a_worker_runs(): void
    {
        $this->artisan('nexusdav:worker-status')
            ->expectsOutput('No worker has been seen.')
            ->assertExitCode(1);

        app(WorkerStatus::class)->beat('worker-a', CarbonImmutable::now());

        $this->artisan('nexusdav:worker-status')
            ->expectsOutputToContain('Worker running: worker-a')
            ->assertExitCode(0);

        DB::table('worker_heartbeats')->update(['beat_at' => CarbonImmutable::now()->subMinutes(10)]);

        $this->artisan('nexusdav:worker-status')
            ->expectsOutputToContain('Worker stale: no heartbeat since')
            ->assertExitCode(1);
    }

    public function test_a_probe_reports_the_worker_that_ran_it(): void
    {
        app(WorkerStatus::class)->beat('worker-a', CarbonImmutable::now());

        // The test queue runs a job at once, as a worker would.
        $this->artisan('nexusdav:worker-status --probe')
            ->expectsOutputToContain('Probe job ran on '.WorkerStatus::workerName())
            ->assertExitCode(0);

        $this->assertNotNull(DB::table('worker_heartbeats')->where('worker', WorkerStatus::workerName())->value('last_probe'));
    }

    public function test_a_probe_that_no_worker_runs_fails_after_the_wait(): void
    {
        Queue::fake();

        $this->artisan('nexusdav:worker-status --probe --wait=1')
            ->expectsOutput('No worker ran the probe job within 1 seconds.')
            ->assertExitCode(1);

        Queue::assertPushed(WorkerProbe::class);
    }

    public function test_a_queued_probe_runs_on_a_database_queue_worker(): void
    {
        config(['queue.default' => 'database']);
        WorkerProbe::dispatch('token-1');

        $this->assertSame(1, DB::table('jobs')->count());

        $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertExitCode(0);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(WorkerStatus::workerName(), app(WorkerStatus::class)->probedBy('token-1'));
        $this->assertNull(app(WorkerStatus::class)->probedBy('token-2'));
    }
}
