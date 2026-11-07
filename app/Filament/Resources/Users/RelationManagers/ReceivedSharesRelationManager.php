<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Filament\Resources\Users\CollectionShareAction;
use App\Models\AddressBook;
use App\Models\AddressBookInstance;
use App\Models\CalendarInstance;
use App\Models\User;
use App\Services\Dav\SharingService;
use App\Support\PanelErrorTranslator;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\On;

/**
 * The calendars and address books other users shared with this user. The
 * rows live in two tables, so the table is built from SharingService's
 * answers; a removal goes back through the service.
 */
class ReceivedSharesRelationManager extends RelationManager
{
    protected static string $relationship = 'calendarInstances';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Shared with this user');
    }

    #[On('collection-shares-changed')]
    public function refreshShares(): void {}

    public function table(Table $table): Table
    {
        // The base table's record action and URL expect an Eloquent model.
        return $table
            ->recordAction(null)
            ->recordUrl(fn (): ?string => null)
            ->records(fn (int $page, int|string $recordsPerPage): LengthAwarePaginator => $this->page($page, (int) $recordsPerPage))
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading(__('Nothing is shared with this user'))
            ->emptyStateIcon(Heroicon::OutlinedUsers)
            ->columns([
                TextColumn::make('type')->label(__('Type')),
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->wrap()
                    ->extraAttributes(['style' => 'word-break: normal; overflow-wrap: anywhere;']),
                TextColumn::make('owner')->label(__('Owner')),
                TextColumn::make('access')->label(__('Access')),
                TextColumn::make('path')
                    ->label(__('DAV path'))
                    ->fontFamily(FontFamily::Mono)
                    ->wrap()
                    ->extraAttributes(['style' => 'overflow-wrap: anywhere;']),
            ])
            ->recordActions([
                Action::make('remove')
                    ->label(__('Remove'))
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('Remove share'))
                    ->modalDescription(__("This share will be removed. The owner's items stay."))
                    ->modalSubmitActionLabel(__('Remove'))
                    ->action(function (array $record): void {
                        $this->remove((string) ($record['kind'] ?? ''), (int) ($record['id'] ?? 0));
                    }),
            ]);
    }

    private function page(int $page, int $perPage): LengthAwarePaginator
    {
        $rows = $this->rows();
        $perPage = max(1, $perPage);

        return new LengthAwarePaginator(
            array_slice($rows, ($page - 1) * $perPage, $perPage, true),
            count($rows),
            $perPage,
            $page,
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rows(): array
    {
        $user = $this->getOwnerRecord();

        if (! $user instanceof User) {
            return [];
        }

        $sharing = app(SharingService::class);
        $calendars = $sharing->receivedCalendarShares($user);
        $books = $sharing->receivedAddressBookShares($user);
        $principals = [];

        foreach ($calendars as $share) {
            $owner = $share->ownerInstance;
            $principals[] = $owner instanceof CalendarInstance ? (string) $owner->getAttribute('principaluri') : '';
        }

        foreach ($books as $share) {
            $book = $share->addressBook;
            $principals[] = $book instanceof AddressBook ? (string) $book->getAttribute('principaluri') : '';
        }

        $owners = CollectionShareAction::usersByUsername($principals);
        $rows = [];

        foreach ($calendars as $share) {
            $owner = $share->ownerInstance;
            $rows['calendar-'.$share->getKey()] = [
                'kind' => 'calendar',
                'id' => (int) $share->getKey(),
                'type' => __('Calendar'),
                'name' => $this->name($share->getAttribute('displayname'), $share->getAttribute('uri')),
                'owner' => $this->owner($owners, $owner instanceof CalendarInstance ? (string) $owner->getAttribute('principaluri') : ''),
                'access' => $this->access((int) $share->getAttribute('access')),
                'path' => '/dav/'.SharingService::sharePath(true, (string) $share->getAttribute('principaluri'), (string) $share->getAttribute('uri')).'/',
            ];
        }

        foreach ($books as $share) {
            $book = $share->addressBook;
            $rows['address-book-'.$share->getKey()] = [
                'kind' => 'address-book',
                'id' => (int) $share->getKey(),
                'type' => __('Address book'),
                'name' => $this->name($share->getAttribute('displayname'), $share->getAttribute('uri')),
                'owner' => $this->owner($owners, $book instanceof AddressBook ? (string) $book->getAttribute('principaluri') : ''),
                'access' => $this->access((int) $share->getAttribute('access')),
                'path' => '/dav/'.SharingService::sharePath(false, (string) $share->getAttribute('principaluri'), (string) $share->getAttribute('uri')).'/',
            ];
        }

        return $rows;
    }

    private function remove(string $kind, int $id): void
    {
        $user = $this->getOwnerRecord();
        $collection = null;

        if ($user instanceof User && $kind === 'calendar') {
            $share = CalendarInstance::query()->with('ownerInstance')->where('principaluri', $user->principal_uri)->where('id', $id)->first();
            $collection = $share instanceof CalendarInstance ? $share->ownerInstance : null;
        } elseif ($user instanceof User && $kind === 'address-book') {
            $share = AddressBookInstance::query()->with('addressBook')->where('principaluri', $user->principal_uri)->where('id', $id)->first();
            $collection = $share instanceof AddressBookInstance ? $share->addressBook : null;
        }

        if (! $user instanceof User || ! ($collection instanceof CalendarInstance || $collection instanceof AddressBook)) {
            Notification::make()
                ->danger()
                ->title(__('Could not save the shares'))
                ->body(__('That collection no longer exists.'))
                ->send();

            return;
        }

        try {
            app(SharingService::class)->unshare($collection, $user);
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->danger()
                ->title(__('Could not save the shares'))
                ->body(PanelErrorTranslator::translate($exception->getMessage()))
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('Share removed'))
            ->send();
        $this->dispatch('collection-shares-changed');
    }

    private function name(mixed $display, mixed $uri): string
    {
        $display = is_string($display) ? trim($display) : '';

        return $display !== '' ? $display : (is_string($uri) ? $uri : '');
    }

    /**
     * @param  Collection<string, User>  $owners
     */
    private function owner(Collection $owners, string $principal): string
    {
        $username = CollectionShareAction::username($principal);
        $user = $username === '' ? null : $owners->get($username);

        return $user instanceof User ? CollectionShareAction::userLabel($user) : ($username !== '' ? $username : $principal);
    }

    private function access(int $access): string
    {
        return $access === SharingService::READ_WRITE ? __('Read and write') : __('Read only');
    }
}
