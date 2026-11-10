<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Diagnostics;
use App\Services\DiagnosticsSummary;
use App\Support\DatabaseHealth;
use App\Support\WorkerStatusLabel;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Number;

/**
 * Quiet when everything is fine. Otherwise counts for the last 24 hours,
 * each linking to Diagnostics. Names, addresses and reasons stay there.
 */
class AttentionWidget extends SchemaWidget
{
    protected static ?int $sort = 5;

    protected function heading(): string
    {
        return __('Needs attention');
    }

    protected function entries(): array
    {
        $diagnostics = Diagnostics::getUrl();
        $lines = [];

        if (! app(DatabaseHealth::class)->isUp()) {
            $lines[] = TextEntry::make('database')
                ->label(__('Database'))
                ->hiddenLabel()
                ->state(__('The database is unavailable'))
                ->color('danger')
                ->icon(Heroicon::XCircle)
                ->iconColor('danger');

            return $lines;
        }

        $summary = app(DiagnosticsSummary::class);
        $failedLogins = $summary->failedLogins();
        $davErrors = $summary->davErrors();

        if ($failedLogins > 0) {
            $lines[] = TextEntry::make('failed_logins')
                ->label(__('Failed logins'))
                ->hiddenLabel()
                ->state(trans_choice(':count failed login in the last 24 hours|:count failed logins in the last 24 hours', $failedLogins, ['count' => Number::format($failedLogins)]))
                ->icon(Heroicon::ExclamationTriangle)
                ->iconColor('warning')
                ->url($diagnostics);
        }

        if ($davErrors > 0) {
            $lines[] = TextEntry::make('dav_errors')
                ->label(__('DAV errors'))
                ->hiddenLabel()
                ->state(trans_choice(':count DAV error in the last 24 hours|:count DAV errors in the last 24 hours', $davErrors, ['count' => Number::format($davErrors)]))
                ->icon(Heroicon::ExclamationTriangle)
                ->iconColor('warning')
                ->url($diagnostics);
        }

        if (WorkerStatusLabel::describe()['warning']) {
            $lines[] = TextEntry::make('worker')
                ->label(__('Queue worker'))
                ->hiddenLabel()
                ->state(__('WebDAV-Push is on, but the queue worker is not running. Push messages wait until it starts.'))
                ->icon(Heroicon::ExclamationTriangle)
                ->iconColor('warning')
                ->url($diagnostics);
        }

        if ($lines === []) {
            $lines[] = TextEntry::make('quiet')
                ->label(__('Status'))
                ->hiddenLabel()
                ->state(__('Nothing needs attention'))
                ->icon(Heroicon::CheckCircle)
                ->iconColor('success');
        }

        return $lines;
    }
}
