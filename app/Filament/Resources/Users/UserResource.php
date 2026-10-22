<?php

namespace App\Filament\Resources\Users;

use App\Exceptions\LastActiveAdminException;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\RelationManagers\AppPasswordsRelationManager;
use App\Models\User;
use App\Services\UserService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $recordTitleAttribute = 'username';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('username')
                    ->required()
                    ->maxLength(63)
                    ->regex(User::USERNAME_PATTERN)
                    ->unique()
                    ->disabled(fn (string $operation): bool => $operation === 'edit')
                    ->dehydrated(fn (string $operation): bool => $operation === 'create')
                    ->helperText(fn (string $operation): string => $operation === 'edit'
                        ? 'Username cannot be changed.'
                        : 'Lowercase letters, numbers, underscores, and hyphens.'),
                TextInput::make('display_name')
                    ->label('Display name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->dehydrated(fn (mixed $state): bool => filled($state))
                    ->required(function (string $operation, Get $get, ?User $record): bool {
                        if (! (bool) $get('is_admin')) {
                            return false;
                        }

                        if ($operation === 'create') {
                            return true;
                        }

                        $stored = $record?->getAttributes()['password'] ?? null;

                        return ! is_string($stored) || $stored === '';
                    })
                    ->helperText('Required for admins. Optional for everyone else. Only the admin panel accepts this password.'),
                Toggle::make('is_admin')
                    ->label('Admin')
                    ->default(false)
                    ->helperText(fn (?User $record): ?string => $record instanceof User && app(UserService::class)->isLastActiveAdmin($record)
                        ? LastActiveAdminException::CANNOT_CHANGE
                        : null),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('username')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('display_name')
                    ->label('Display name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_admin')
                    ->label('Admin')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                self::disableAction(),
                self::enableAction(),
                self::deleteAction(),
            ]);
    }

    public static function disableAction(): Action
    {
        return Action::make('disable')
            ->label('Disable')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (User $record): bool => $record->is_active)
            ->requiresConfirmation()
            ->modalDescription(fn (User $record): string => app(UserService::class)->isLastActiveAdmin($record)
                ? LastActiveAdminException::CANNOT_CHANGE
                : 'This user will not be able to sign in.')
            ->action(function (User $record, Action $action): void {
                try {
                    app(UserService::class)->update($record, ['is_active' => false]);
                } catch (LastActiveAdminException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Cannot disable the last active admin')
                        ->body($exception->getMessage())
                        ->send();

                    $action->halt();
                }
            });
    }

    public static function enableAction(): Action
    {
        return Action::make('enable')
            ->label('Enable')
            ->icon(Heroicon::OutlinedCheck)
            ->color('success')
            ->visible(fn (User $record): bool => ! $record->is_active)
            ->requiresConfirmation()
            ->modalDescription('This user will be able to sign in again.')
            ->action(function (User $record): void {
                app(UserService::class)->update($record, ['is_active' => true]);
            });
    }

    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription(fn (User $record): string => app(UserService::class)->isLastActiveAdmin($record)
                ? LastActiveAdminException::CANNOT_DELETE
                : 'The user, their principal, and their app passwords will be deleted.')
            ->using(function (User $record): bool {
                try {
                    app(UserService::class)->delete($record);
                } catch (LastActiveAdminException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Cannot delete the last active admin')
                        ->body($exception->getMessage())
                        ->send();

                    return false;
                }

                return true;
            });
    }

    public static function getRelations(): array
    {
        return [
            AppPasswordsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
