<?php

namespace App\Filament\Widgets\Diagnostics;

use App\Models\PushSubscription;
use App\Services\Push\PushConfig;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every WebDAV-Push subscription, with revoke. Shows the push service's
 * host only, never its address or the client's keys. Shown on Diagnostics
 * only.
 */
class PushSubscriptionsTable extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Push subscriptions'))
            ->description(PushConfig::enabled()
                ? __('Devices that get WebDAV-Push messages. A revoked subscription comes back when the device next subscribes; revoke its app password to stop it for good.')
                : __('WebDAV-Push is off: these subscriptions get no messages and expire.'))
            ->query(PushSubscription::query()->with('user'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('user.username')->label(__('User'))->searchable(),
                TextColumn::make('path')->label(__('Collection'))->searchable()->wrap(),
                TextColumn::make('host')
                    ->label(__('Push service'))
                    ->state(fn (PushSubscription $record): string => $record->endpointHost()),
                TextColumn::make('created_at')->label(__('Created'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('expires_at')->label(__('Expires'))->dateTime()->sortable(),
                TextColumn::make('last_success_at')->label(__('Last push'))->dateTime()->sortable()->placeholder(__('Never')),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->state(fn (PushSubscription $record): string => self::status($record))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'ok' => 'success',
                        'failing' => 'danger',
                        'expired', 'removing' => 'gray',
                        default => 'info',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'ok' => __('Delivered'),
                        'failing' => __('Failing'),
                        'expired' => __('Expired'),
                        'removing' => __('Removing'),
                        default => __('No push yet'),
                    })
                    ->tooltip(fn (PushSubscription $record): ?string => self::status($record) === 'failing' ? $record->last_failure : null),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label(__('Status'))
                    ->options(['active' => __('Active'), 'failing' => __('Failing'), 'expired' => __('Expired')])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'active' => $query->where('expires_at', '>', now())->whereNull('removed_at'),
                            'failing' => $query->whereNotNull('last_failure_at')->where(fn (Builder $q) => $q->whereNull('last_success_at')->orWhereColumn('last_failure_at', '>', 'last_success_at')),
                            'expired' => $query->where('expires_at', '<=', now()),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label(__('Revoke'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('The device gets no more push messages for this collection until it subscribes again.'))
                    ->action(function (PushSubscription $record): void {
                        $record->delete();

                        Notification::make()->success()->title(__('Push subscription revoked'))->send();
                    }),
            ])
            ->emptyStateHeading(__('No push subscriptions'));
    }

    private static function status(PushSubscription $record): string
    {
        if ($record->removed_at !== null) {
            return 'removing';
        }

        if (! $record->expires_at->isFuture()) {
            return 'expired';
        }

        if ($record->last_failure_at !== null && ($record->last_success_at === null || $record->last_failure_at->greaterThan($record->last_success_at))) {
            return 'failing';
        }

        return $record->last_success_at === null ? 'waiting' : 'ok';
    }
}
