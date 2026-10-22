<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Models\AppPassword;
use App\Models\User;
use App\Services\AppPasswordService;
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
use InvalidArgumentException;
use RuntimeException;

class AppPasswordsRelationManager extends RelationManager
{
    protected static string $relationship = 'appPasswords';

    protected static ?string $title = 'App passwords';

    public ?string $plainTextToken = null;

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('New app password')
                    ->description('Copy this password now. It is shown once and is not stored.')
                    ->visible(fn (): bool => filled($this->plainTextToken))
                    ->schema([
                        TextEntry::make('plain_text_token')
                            ->label('Password')
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
                TextColumn::make('name'),
                TextColumn::make('public_id')
                    ->label('Public id'),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime()
                    ->placeholder('Never'),
                TextColumn::make('revoked_at')
                    ->label('Revoked')
                    ->dateTime()
                    ->placeholder('—'),
                TextColumn::make('last_used_at')
                    ->label('Last used')
                    ->dateTime()
                    ->placeholder('Never'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                Action::make('generate')
                    ->label('Generate app password')
                    ->icon(Heroicon::OutlinedKey)
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255)
                            ->helperText('A label for this device, such as "Pixel 7".'),
                        DateTimePicker::make('expires_at')
                            ->label('Expires')
                            ->helperText('Optional.'),
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
                                ->title('Could not create the app password')
                                ->body($exception->getMessage())
                                ->send();

                            throw new Halt;
                        }

                        $this->plainTextToken = $issued['token'];

                        Notification::make()
                            ->success()
                            ->title('App password created')
                            ->body('Copy the password shown on this page. It will not be shown again.')
                            ->persistent()
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label('Revoke')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Revoking keeps this password but it can no longer be used.')
                    ->visible(fn (AppPassword $record): bool => $record->revoked_at === null)
                    ->action(function (AppPassword $record): void {
                        app(AppPasswordService::class)->revoke($record);

                        Notification::make()
                            ->success()
                            ->title('App password revoked')
                            ->send();
                    }),
            ]);
    }
}
