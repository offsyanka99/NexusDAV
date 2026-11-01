<?php

namespace App\Filament\Widgets\Diagnostics;

use App\Models\AppPassword;
use App\Services\AppPasswordService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every app password of every user, with revoke. Shown on Diagnostics only.
 */
class AppPasswordsTable extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('App passwords'))
            ->description(__('All users. A revoked or expired app password no longer signs in.'))
            ->query(AppPassword::query()->with('user'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('user.username')->label(__('User'))->searchable(),
                TextColumn::make('name')->label(__('Device'))->searchable(),
                TextColumn::make('public_id')->label(__('Public id'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label(__('Created'))->dateTime()->sortable(),
                TextColumn::make('last_used_at')->label(__('Last used'))->dateTime()->sortable()->placeholder(__('Never')),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->state(fn (AppPassword $record): string => self::status($record))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'expired' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active' => __('Active'),
                        'expired' => __('Expired'),
                        default => __('Revoked'),
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(['active' => __('Active'), 'revoked' => __('Revoked'), 'expired' => __('Expired')])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'active' => $query->whereNull('revoked_at')->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now())),
                            'revoked' => $query->whereNotNull('revoked_at'),
                            'expired' => $query->whereNull('revoked_at')->whereNotNull('expires_at')->where('expires_at', '<=', now()),
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
                    ->modalDescription(__('Revoking keeps this password but it can no longer be used.'))
                    ->visible(fn (AppPassword $record): bool => $record->revoked_at === null)
                    ->action(function (AppPassword $record): void {
                        app(AppPasswordService::class)->revoke($record);

                        Notification::make()->success()->title(__('App password revoked'))->send();
                    }),
            ])
            ->emptyStateHeading(__('No app passwords'));
    }

    private static function status(AppPassword $record): string
    {
        if ($record->revoked_at !== null) {
            return 'revoked';
        }

        if ($record->expires_at?->isPast()) {
            return 'expired';
        }

        return 'active';
    }
}
