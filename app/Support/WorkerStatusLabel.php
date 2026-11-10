<?php

namespace App\Support;

use App\Services\Push\PushConfig;
use App\Services\WorkerStatus;
use Filament\Support\Icons\Heroicon;

/**
 * The queue worker's state as the panel shows it: on the Dashboard
 * (Services, Needs attention), on Diagnostics and next to the WebDAV-Push
 * switch. Not running is a warning only while push is on, because nothing
 * else needs the worker.
 */
final class WorkerStatusLabel
{
    /**
     * @return array{state: string, label: string, color: string, icon: Heroicon, detail: string, warning: bool}
     */
    public static function describe(): array
    {
        $status = app(WorkerStatus::class)->status();
        $push = PushConfig::enabled();
        $running = $status['state'] === WorkerStatus::RUNNING;
        $beat = $status['beat_at']?->diffForHumans();
        $worker = (string) $status['worker'];

        $detail = match ($status['state']) {
            WorkerStatus::RUNNING => __('Running: last heartbeat :time from :worker.', ['time' => (string) $beat, 'worker' => $worker]),
            WorkerStatus::STALE => __('Not running: last heartbeat :time from :worker.', ['time' => (string) $beat, 'worker' => $worker]),
            default => __('No queue worker has run on this server.'),
        };

        return [
            'state' => $status['state'],
            'label' => $running ? __('Running') : __('Not running'),
            'color' => $running ? 'success' : ($push ? 'warning' : 'gray'),
            'icon' => $running ? Heroicon::CheckCircle : ($push ? Heroicon::ExclamationTriangle : Heroicon::MinusCircle),
            'detail' => $detail,
            'warning' => $push && ! $running,
        ];
    }
}
