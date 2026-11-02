<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Models\AddressBook;
use App\Models\User;
use App\Services\Dav\CollectionService;
use App\Services\UserService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use InvalidArgumentException;
use RuntimeException;

class AddressBooksRelationManager extends RelationManager
{
    protected static string $relationship = 'addressBooks';

    protected static ?string $title = 'Address books';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('uri')
                    ->label('URI'),
                TextColumn::make('displayname')
                    ->label('Name'),
                TextColumn::make('cards_count')
                    ->label('Contacts')
                    ->counts('cards'),
                TextColumn::make('dav_path')
                    ->label('DAV path')
                    ->fontFamily(FontFamily::Mono)
                    ->state(function (AddressBook $record): string {
                        $owner = $this->getOwnerRecord();
                        $username = $owner instanceof User ? $owner->username : '';

                        return '/dav/addressbooks/'.$username.'/'.$record->uri.'/';
                    }),
            ])
            ->headerActions([
                Action::make('create')
                    ->label('Add address book')
                    ->icon(Heroicon::OutlinedPlus)
                    ->modalSubmitActionLabel('Save')
                    ->schema([
                        $this->uriField(false),
                        $this->displayNameField(),
                        $this->descriptionField(),
                    ])
                    ->action(function (array $data): void {
                        try {
                            app(CollectionService::class)->createAddressBook(
                                $this->owner(),
                                (string) ($data['uri'] ?? ''),
                                (string) ($data['display_name'] ?? ''),
                                isset($data['description']) ? (string) $data['description'] : null,
                            );
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Could not create the address book')
                                ->body($exception->getMessage())
                                ->send();

                            throw new Halt;
                        }

                        Notification::make()
                            ->success()
                            ->title('Address book created')
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modalSubmitActionLabel('Save')
                    ->fillForm(fn (AddressBook $record): array => [
                        'uri' => (string) $record->uri,
                        'display_name' => (string) $record->displayname,
                        'description' => (string) ($record->description ?? ''),
                    ])
                    ->schema([
                        $this->uriField(true),
                        $this->displayNameField(),
                        $this->descriptionField(),
                    ])
                    ->action(function (AddressBook $record, array $data): void {
                        try {
                            app(CollectionService::class)->updateAddressBook(
                                $this->owner(),
                                $record,
                                (string) ($data['display_name'] ?? ''),
                                isset($data['description']) ? (string) $data['description'] : null,
                            );
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Could not save the address book')
                                ->body($exception->getMessage())
                                ->send();

                            throw new Halt;
                        }

                        Notification::make()
                            ->success()
                            ->title('Address book saved')
                            ->send();
                    }),
                Action::make('delete')
                    ->label('Delete')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('The address book and its contacts will be deleted.')
                    ->action(function (AddressBook $record): void {
                        app(CollectionService::class)->deleteAddressBook($this->owner(), $record);

                        Notification::make()
                            ->success()
                            ->title('Address book deleted')
                            ->send();
                    }),
            ]);
    }

    private function uriField(bool $editing): TextInput
    {
        $field = TextInput::make('uri')
            ->label('URI')
            ->maxLength(200);

        if ($editing) {
            return $field
                ->disabled()
                ->dehydrated(false);
        }

        return $field
            ->required()
            ->trim()
            ->regex(CollectionService::SLUG_PATTERN)
            ->helperText('Lowercase letters, numbers, underscores, and hyphens. Unique for this user.')
            ->default(function (): string {
                $owner = $this->getOwnerRecord();

                return $owner instanceof User
                    ? app(CollectionService::class)->suggestedAddressBookUri($owner)
                    : UserService::DEFAULT_ADDRESS_BOOK_URI;
            })
            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value)) {
                    $fail('The URI must be lowercase and URL-safe.');

                    return;
                }

                $owner = $this->getOwnerRecord();

                if ($owner instanceof User && app(CollectionService::class)->addressBookUriTaken($owner, $value)) {
                    $fail('That URI is already used.');
                }
            });
    }

    private function displayNameField(): TextInput
    {
        return TextInput::make('display_name')
            ->label('Display name')
            ->required()
            ->maxLength(255);
    }

    private function descriptionField(): Textarea
    {
        return Textarea::make('description')
            ->label('Description')
            ->rows(3)
            ->maxLength(2000);
    }

    private function owner(): User
    {
        $owner = $this->getOwnerRecord();

        if (! $owner instanceof User) {
            throw new RuntimeException('Address books belong to a user.');
        }

        return $owner;
    }
}
