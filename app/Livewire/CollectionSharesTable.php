<?php

namespace App\Livewire;

use App\Filament\Resources\Users\CollectionShareAction;
use App\Models\AddressBook;
use App\Models\AddressBookInstance;
use App\Models\CalendarInstance;
use App\Models\User;
use App\Services\Dav\SharingService;
use App\Support\PanelErrorTranslator;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Livewire\Component;

/**
 * The people a calendar or address book is shared with. It is embedded in
 * the "Shared with" modal. The rows are the sharees' instances and every
 * change goes through SharingService.
 */
class CollectionSharesTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public const CALENDAR = 'calendar';

    public const ADDRESS_BOOK = 'address_book';

    public string $kind = self::CALENDAR;

    public int $collectionId = 0;

    /** @var array<string, User>|null */
    private ?array $usersCache = null;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->sharesQuery())
            ->defaultSort('id')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading(__('Not shared with anyone'))
            ->emptyStateIcon(Heroicon::OutlinedUsers)
            ->columns([
                TextColumn::make('user')
                    ->label(__('User'))
                    ->state(fn (Model $record): string => $this->userLabel($record))
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $this->searchSharees($query, $search)),
                SelectColumn::make('access')
                    ->label(CollectionShareAction::accessLabel())
                    ->options(CollectionShareAction::accessOptions())
                    ->selectablePlaceholder(false)
                    ->updateStateUsing(function (Model $record, mixed $state): int {
                        $access = CollectionShareAction::accessValue($state);

                        if ($access === null || ! $this->change([$record], $access)) {
                            return (int) $record->getAttribute('access');
                        }

                        return $access;
                    }),
            ])
            ->filters([
                SelectFilter::make('access')
                    ->label(__('Access'))
                    ->options(CollectionShareAction::accessOptions()),
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
                    ->action(fn (Model $record) => $this->remove([$record])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('setAccess')
                        ->label(__('Set access'))
                        ->icon(Heroicon::OutlinedLockClosed)
                        ->modalHeading(__('Set access'))
                        ->modalSubmitActionLabel(__('Save'))
                        ->schema([
                            Select::make('access')
                                ->label(CollectionShareAction::accessLabel())
                                ->validationAttribute(__('Access'))
                                ->options(CollectionShareAction::accessOptions())
                                ->default(SharingService::READ)
                                ->selectablePlaceholder(false)
                                // The info icon is the first focusable element, and its tooltip opens on focus.
                                ->autofocus()
                                ->required(),
                        ])
                        ->action(function (EloquentCollection $records, array $data): void {
                            $access = CollectionShareAction::accessValue($data['access'] ?? null);

                            if ($access !== null && $this->change($records->all(), $access)) {
                                Notification::make()->success()->title(__('Shares saved'))->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('remove')
                        ->label(__('Remove'))
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading(__('Remove shares'))
                        ->modalDescription(__("The selected shares will be removed. The owner's items stay."))
                        ->modalSubmitActionLabel(__('Remove'))
                        ->action(fn (EloquentCollection $records) => $this->remove($records->all()))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public function render(): View
    {
        return view('livewire.collection-shares-table');
    }

    /**
     * @return Builder<CalendarInstance>|Builder<AddressBookInstance>
     */
    private function sharesQuery(): Builder
    {
        $collection = $this->collection();

        if ($collection instanceof CalendarInstance) {
            return CalendarInstance::query()
                ->where('calendarid', $collection->calendarid)
                ->whereIn('access', [SharingService::READ, SharingService::READ_WRITE]);
        }

        if ($collection instanceof AddressBook) {
            return AddressBookInstance::query()->where('addressbookid', $collection->getKey());
        }

        return CalendarInstance::query()->whereRaw('1 = 0');
    }

    private function collection(): CalendarInstance|AddressBook|null
    {
        if ($this->kind === self::ADDRESS_BOOK) {
            return AddressBook::query()->find($this->collectionId);
        }

        $instance = CalendarInstance::query()->find($this->collectionId);

        return $instance instanceof CalendarInstance && (int) $instance->getAttribute('access') === CalendarInstance::OWNER
            ? $instance
            : null;
    }

    /**
     * @param  Builder<CalendarInstance>|Builder<AddressBookInstance>  $query
     * @return Builder<CalendarInstance>|Builder<AddressBookInstance>
     */
    private function searchSharees(Builder $query, string $search): Builder
    {
        $like = '%'.$search.'%';
        $principals = User::query()
            ->where(function (Builder $users) use ($like): void {
                $users->whereLike('username', $like)
                    ->orWhereLike('display_name', $like)
                    ->orWhereLike('email', $like);
            })
            ->limit(500)
            ->get(['username'])
            ->map(fn (User $user): string => 'principals/'.$user->username)
            ->all();

        return $query->whereIn('principaluri', $principals);
    }

    private function userLabel(Model $share): string
    {
        $username = CollectionShareAction::username((string) $share->getAttribute('principaluri'));
        $user = $this->users()[$username] ?? null;

        return $user instanceof User ? CollectionShareAction::userLabel($user) : $username;
    }

    /**
     * @return array<string, User>
     */
    private function users(): array
    {
        if ($this->usersCache === null) {
            $principals = $this->getTableRecords()->map(
                fn (Model $share): string => (string) $share->getAttribute('principaluri'),
            )->all();
            $this->usersCache = CollectionShareAction::usersByUsername(array_values($principals))->all();
        }

        return $this->usersCache;
    }

    /**
     * @param  list<Model>  $shares
     */
    private function change(array $shares, int $access): bool
    {
        return $this->apply($shares, function (CalendarInstance|AddressBook $collection, User $user) use ($access): void {
            app(SharingService::class)->changeAccess($collection, $user, $access);
        });
    }

    /**
     * @param  list<Model>  $shares
     */
    private function remove(array $shares): void
    {
        if ($this->apply($shares, function (CalendarInstance|AddressBook $collection, User $user): void {
            app(SharingService::class)->unshare($collection, $user);
        })) {
            Notification::make()
                ->success()
                ->title(count($shares) === 1 ? __('Share removed') : __('Shares removed'))
                ->send();
        }
    }

    /**
     * @param  list<Model>  $shares
     * @param  callable(CalendarInstance|AddressBook, User): void  $change
     */
    private function apply(array $shares, callable $change): bool
    {
        $collection = $this->collection();

        if ($collection === null) {
            $this->fail(__('That collection no longer exists.'));

            return false;
        }

        $users = CollectionShareAction::usersByUsername(array_map(
            fn (Model $share): string => (string) $share->getAttribute('principaluri'),
            $shares,
        ));

        try {
            foreach ($shares as $share) {
                $user = $users->get(CollectionShareAction::username((string) $share->getAttribute('principaluri')));

                if ($user instanceof User) {
                    $change($collection, $user);
                }
            }
        } catch (InvalidArgumentException $exception) {
            $this->fail(PanelErrorTranslator::translate($exception->getMessage()));

            return false;
        } finally {
            $this->usersCache = null;
            $this->dispatch('collection-shares-changed');
        }

        return true;
    }

    private function fail(string $body): void
    {
        Notification::make()
            ->danger()
            ->title(__('Could not save the shares'))
            ->body($body)
            ->send();
    }
}
