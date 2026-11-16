<?php

namespace App\Filament\Portal;

use App\Models\AppPassword;
use App\Models\User;
use App\Portal\PortalPage;
use App\Services\AppPasswordService;
use App\Services\Portal\PortalLog;
use App\Support\PanelErrorTranslator;
use DateTimeInterface;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use InvalidArgumentException;

/**
 * The signed-in user's own app passwords, one per device, for DAV. The list
 * and every action are limited to this user's rows, whatever id the browser
 * sends.
 */
class AppPasswords extends PortalPage implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'app-passwords';

    /**
     * Shown once, right after Generate; never stored.
     */
    public ?string $plainTextToken = null;

    public function getTitle(): string
    {
        return __('App passwords');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('New app password'))
                    ->description(__('Copy this password now. It is shown once and is not stored.'))
                    ->visible(fn (): bool => filled($this->plainTextToken))
                    ->schema([
                        TextEntry::make('plain_text_token')
                            ->label(__('Password'))
                            ->state(fn (): ?string => $this->plainTextToken)
                            ->copyable()
                            ->wrap()
                            ->fontFamily(FontFamily::Mono),
                    ]),
                EmbeddedTable::make(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => AppPassword::query()->where('user_id', $this->user()->getKey()))
            ->description(__('Calendar, contacts and files apps on your devices sign in with an app password, never with your main password. Create one per device, and revoke it when the device is lost or replaced.'))
            ->columns([
                TextColumn::make('name')->label(__('Name')),
                TextColumn::make('created_at')
                    ->label(__('Created'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('last_used_at')
                    ->label(__('Last used'))
                    ->dateTime()
                    ->placeholder(__('Never')),
                TextColumn::make('expires_at')
                    ->label(__('Expires'))
                    ->dateTime()
                    ->placeholder(__('Never')),
                TextColumn::make('revoked_at')
                    ->label(__('Revoked'))
                    ->dateTime()
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('No app passwords'))
            ->headerActions([
                Action::make('generate')
                    ->label(__('Generate app password'))
                    ->icon(Heroicon::OutlinedKey)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->required()
                            ->maxLength(255)
                            ->helperText(__('A label for this device, such as "Pixel 7".')),
                        DateTimePicker::make('expires_at')
                            ->label(__('Expires'))
                            ->helperText(__('Optional.')),
                    ])
                    ->action(function (array $data): void {
                        $user = $this->user();
                        $expiresAt = $data['expires_at'] ?? null;

                        try {
                            $issued = app(AppPasswordService::class)->issue(
                                $user,
                                (string) $data['name'],
                                is_string($expiresAt) || $expiresAt instanceof DateTimeInterface ? $expiresAt : null,
                            );
                        } catch (InvalidArgumentException $exception) {
                            $this->notifyError(__('Could not create the app password'), PanelErrorTranslator::translate($exception->getMessage()));

                            throw new Halt;
                        }

                        $this->plainTextToken = $issued['token'];

                        PortalLog::info('Portal app password created', ['username' => $user->username, 'public_id' => $issued['app_password']->public_id]);

                        Notification::make()
                            ->success()
                            ->title(__('App password created'))
                            ->body(__('Copy the password shown on this page. It will not be shown again.'))
                            ->persistent()
                            ->send();
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
                        $user = $this->user();

                        abort_unless((int) $record->user_id === (int) $user->getKey(), 404);

                        app(AppPasswordService::class)->revoke($record);

                        PortalLog::info('Portal app password revoked', ['username' => $user->username, 'public_id' => $record->public_id]);

                        Notification::make()
                            ->success()
                            ->title(__('App password revoked'))
                            ->send();
                    }),
            ]);
    }

    private function user(): User
    {
        $user = Filament::auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
