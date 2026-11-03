<?php

namespace App\Filament\Widgets\Diagnostics;

use App\Models\DiagnosticEvent;
use App\Services\DiagnosticsSummary;
use App\Support\DiagnosticReason;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Rejected logins on the admin panel and on DAV. Shown on Diagnostics only.
 */
class FailedLoginsTable extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Recent failed logins'))
            ->description(__('Kept for :days days. Repeats from the same address within a minute share one row.', [
                'days' => (int) config('nexusdav.diagnostics.retention_days', 30),
            ]))
            ->query(DiagnosticsSummary::failedLoginsQuery())
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('occurred_at')->label(__('Time'))->dateTime()->sortable(),
                TextColumn::make('source')
                    ->label(__('Source'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === DiagnosticEvent::SOURCE_DAV ? 'DAV' : __('Admin panel')),
                TextColumn::make('ip')->label(__('Address')),
                TextColumn::make('username')->label(__('User'))->searchable(),
                TextColumn::make('reason')
                    ->label(__('Reason'))
                    ->formatStateUsing(fn (string $state): string => DiagnosticReason::label($state)),
                TextColumn::make('repeat_count')->label(__('Count'))->numeric(),
                TextColumn::make('request_id')
                    ->label(__('Request ID'))
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('—')
                    ->copyable()
                    ->searchable()
                    ->tooltip(__('Of the first occurrence. Search the container log for it.'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('source')
                    ->label(__('Source'))
                    ->options([
                        DiagnosticEvent::SOURCE_ADMIN => __('Admin panel'),
                        DiagnosticEvent::SOURCE_DAV => 'DAV',
                    ]),
            ])
            ->emptyStateHeading(__('No failed logins'));
    }
}
