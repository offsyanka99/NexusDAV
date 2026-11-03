<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Models\AppPassword;
use App\Models\User;
use App\Services\AppPasswordService;
use App\Support\PanelErrorTranslator;
use DateTimeInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RuntimeException;

class AppPasswordsRelationManager extends RelationManager
{
    protected static string $relationship = 'appPasswords';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('App passwords');
    }

    public ?string $plainTextToken = null;

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
            ->columns([
                TextColumn::make('name')->label(__('Name')),
                TextColumn::make('public_id')
                    ->label(__('Public id')),
                TextColumn::make('expires_at')
                    ->label(__('Expires'))
                    ->dateTime()
                    ->placeholder(__('Never')),
                TextColumn::make('revoked_at')
                    ->label(__('Revoked'))
                    ->dateTime()
                    ->placeholder('—'),
                TextColumn::make('last_used_at')
                    ->label(__('Last used'))
                    ->dateTime()
                    ->placeholder(__('Never')),
                TextColumn::make('created_at')
                    ->label(__('Created'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
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
                        $owner = $this->getOwnerRecord();

                        if (! $owner instanceof User) {
                            throw new RuntimeException('App passwords belong to a user.');
                        }

                        $expiresAt = $data['expires_at'] ?? null;

                        try {
                            $issued = app(AppPasswordService::class)->issue(
                                $owner,
                                (string) $data['name'],
                                is_string($expiresAt) || $expiresAt instanceof DateTimeInterface ? $expiresAt : null,
                            );
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()
                                ->danger()
                                ->title(__('Could not create the app password'))
                                ->body(PanelErrorTranslator::translate($exception->getMessage()))
                                ->send();

                            throw new Halt;
                        }

                        $this->plainTextToken = $issued['token'];

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
                        app(AppPasswordService::class)->revoke($record);

                        Notification::make()
                            ->success()
                            ->title(__('App password revoked'))
                            ->send();
                    }),
            ]);
    }
}
