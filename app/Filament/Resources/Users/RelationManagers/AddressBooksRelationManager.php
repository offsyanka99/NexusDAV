<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Filament\Resources\Users\CollectionShareAction;
use App\Models\AddressBook;
use App\Models\User;
use App\Services\Dav\CollectionService;
use App\Services\UserService;
use App\Support\PanelErrorTranslator;
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
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RuntimeException;

class AddressBooksRelationManager extends RelationManager
{
    protected static string $relationship = 'addressBooks';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Address books');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('uri')
                    ->label(__('URI'))
                    ->wrap()
                    ->extraAttributes(['style' => 'word-break: normal; overflow-wrap: anywhere;']),
                TextColumn::make('displayname')
                    ->label(__('Name'))
                    ->wrap()
                    ->extraAttributes(['style' => 'word-break: normal; overflow-wrap: anywhere;']),
                TextColumn::make('cards_count')
                    ->label(__('Contacts'))
                    ->counts('cards'),
                CollectionShareAction::column(),
                TextColumn::make('dav_path')
                    ->label(__('DAV path'))
                    ->fontFamily(FontFamily::Mono)
                    ->wrap()
                    ->extraAttributes(['style' => 'overflow-wrap: anywhere;'])
                    ->state(function (AddressBook $record): string {
                        $owner = $this->getOwnerRecord();
                        $username = $owner instanceof User ? $owner->username : '';

                        return '/dav/addressbooks/'.$username.'/'.$record->uri.'/';
                    }),
            ])
            ->headerActions([
                Action::make('create')
                    ->label(__('Add address book'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->modalSubmitActionLabel(__('Save'))
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
                                ->title(__('Could not create the address book'))
                                ->body(PanelErrorTranslator::translate($exception->getMessage()))
                                ->send();

                            throw new Halt;
                        }

                        Notification::make()
                            ->success()
                            ->title(__('Address book created'))
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label(__('Edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modalSubmitActionLabel(__('Save'))
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
                                ->title(__('Could not save the address book'))
                                ->body(PanelErrorTranslator::translate($exception->getMessage()))
                                ->send();

                            throw new Halt;
                        }

                        Notification::make()
                            ->success()
                            ->title(__('Address book saved'))
                            ->send();
                    }),
                CollectionShareAction::make(),
                Action::make('delete')
                    ->label(__('Delete'))
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('The address book, its contacts, and its shares will be deleted.'))
                    ->action(function (AddressBook $record): void {
                        app(CollectionService::class)->deleteAddressBook($this->owner(), $record);

                        Notification::make()
                            ->success()
                            ->title(__('Address book deleted'))
                            ->send();
                    }),
            ]);
    }

    private function uriField(bool $editing): TextInput
    {
        $field = TextInput::make('uri')
            ->label(__('URI'))
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
            ->helperText(__('Lowercase letters, numbers, underscores, and hyphens. Unique for this user.'))
            ->default(function (): string {
                $owner = $this->getOwnerRecord();

                return $owner instanceof User
                    ? app(CollectionService::class)->suggestedAddressBookUri($owner)
                    : UserService::DEFAULT_ADDRESS_BOOK_URI;
            })
            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value)) {
                    $fail(__('The URI must be lowercase and URL-safe.'));

                    return;
                }

                $owner = $this->getOwnerRecord();

                if ($owner instanceof User && app(CollectionService::class)->addressBookUriTaken($owner, $value)) {
                    $fail(__('That URI is already used.'));
                }
            });
    }

    private function displayNameField(): TextInput
    {
        return TextInput::make('display_name')
            ->label(__('Display name'))
            ->required()
            ->maxLength(255);
    }

    private function descriptionField(): Textarea
    {
        return Textarea::make('description')
            ->label(__('Description'))
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
