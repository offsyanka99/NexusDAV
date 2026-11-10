<?php

namespace App\Filament\Widgets\Diagnostics;

use App\Filament\Widgets\SchemaWidget;
use App\Support\WorkerStatusLabel;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

/**
 * Whether the queue worker runs, and how many jobs wait for it. Shown on
 * Diagnostics only.
 */
class QueueWorkerCard extends SchemaWidget
{
    protected static bool $isDiscovered = false;

    protected function heading(): string
    {
        return __('Queue worker');
    }

    protected function entries(): array
    {
        $worker = WorkerStatusLabel::describe();
        $waiting = DB::table('jobs')->count();

        $entries = [
            TextEntry::make('worker')
                ->label(__('Status'))
                ->state($worker['detail'])
                ->icon($worker['icon'])
                ->iconColor($worker['color']),
            TextEntry::make('waiting')
                ->label(__('Jobs waiting'))
                ->state(Number::format($waiting)),
        ];

        if ($worker['warning']) {
            $entries[] = TextEntry::make('push')
                ->hiddenLabel()
                ->state(__('WebDAV-Push is on: its messages wait in the queue until a worker runs. Start the worker as the guide shows.'))
                ->color('warning');
        }

        return $entries;
    }
}
