<?php

namespace App\Filament\Resources\Users;

use App\Livewire\CollectionSharesTable;
use App\Models\AddressBook;
use App\Models\CalendarInstance;
use App\Models\User;
use App\Services\Dav\SharingService;
use App\Support\PanelErrorTranslator;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Livewire;
use Filament\Support\Enums\IconSize;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Support\View\ComponentAttributeBag as FilamentComponentAttributeBag;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;
use Illuminate\Support\Number;
use InvalidArgumentException;

/**
 * The panel shares through SharingService. It does not insert share rows.
 */
final class CollectionShareAction
{
    public static function make(): Action
    {
        return Action::make('share')
            ->label(__('Share'))
            ->icon(Heroicon::OutlinedShare)
            ->modalHeading(__('Share'))
            ->modalSubmitActionLabel(__('Share'))
            ->fillForm(['sharee_ids' => [], 'share_access' => SharingService::READ])
            ->schema([
                self::userSelect(),
                self::accessSelect(),
            ])
            ->action(function (CalendarInstance|AddressBook $record, array $data): void {
                self::save($record, $data);
            });
    }

    /**
     * The people the collection is shared with, as a table that scrolls and
     * pages inside a slide-over. Access and removal are changed there.
     */
    public static function list(): Action
    {
        return Action::make('sharedWith')
            ->label(__('Shared with'))
            ->icon(Heroicon::OutlinedUsers)
            ->slideOver()
            ->modalHeading(__('Shared with'))
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitAction(false)
            ->schema(fn (CalendarInstance|AddressBook $record): array => [
                Livewire::make(CollectionSharesTable::class, [
                    'kind' => $record instanceof CalendarInstance ? CollectionSharesTable::CALENDAR : CollectionSharesTable::ADDRESS_BOOK,
                    'collectionId' => (int) $record->getKey(),
                ])->key('shares-'.$record::class.'-'.$record->getKey()),
            ]);
    }

    public static function column(): TextColumn
    {
        return TextColumn::make('sharees_count')
            ->label(__('Shared'))
            ->alignEnd()
            ->state(fn (CalendarInstance|AddressBook $record): string => Number::format(
                app(SharingService::class)->sharees($record)->count(),
            ));
    }

    public static function deletionNote(User $user): string
    {
        return __('The user, their principal, calendars, events, address books, contacts, and app passwords will be deleted. Shares they own: :owned. Shares they received: :received.', [
            'owned' => self::joinLabels(self::ownedLabels($user)),
            'received' => self::joinLabels(self::receivedLabels($user)),
        ]);
    }

    private static function userSelect(): Select
    {
        return Select::make('sharee_ids')
            ->label(__('Users'))
            ->multiple()
            ->required()
            ->searchable()
            ->placeholder(__('Search by username, display name, or email.'))
            ->getSearchResultsUsing(function (Select $component, ?string $search): array {
                return self::searchUsers(self::collectionFrom($component), $search);
            })
            ->getOptionLabelsUsing(function (array $values): array {
                $ids = array_values(array_filter(array_map(self::userId(...), $values)));
                $labels = [];

                foreach (User::query()->whereKey($ids)->get() as $user) {
                    $labels[(int) $user->getKey()] = self::userLabel($user);
                }

                return $labels;
            })
            ->rule(function (Select $component): Closure {
                return function (string $attribute, mixed $value, Closure $fail) use ($component): void {
                    $record = self::collectionFrom($component);

                    if (! $record instanceof CalendarInstance && ! $record instanceof AddressBook) {
                        $fail(__('That user cannot be chosen.'));

                        return;
                    }

                    $excluded = self::excludedIds($record);

                    foreach (is_array($value) ? $value : [$value] as $item) {
                        $id = self::userId($item);

                        if ($id === null || in_array($id, $excluded, true) || ! User::query()->whereKey($id)->exists()) {
                            $fail(__('That user cannot be chosen.'));

                            return;
                        }
                    }
                };
            });
    }

    private static function accessSelect(): Select
    {
        return Select::make('share_access')
            ->label(self::accessLabel())
            ->validationAttribute(__('Access'))
            ->options(self::accessOptions())
            ->default(SharingService::READ)
            ->selectablePlaceholder(false)
            ->required()
            ->rule(fn (): Closure => self::accessRule());
    }

    /**
     * Shares the collection with every chosen user at one access level.
     * Users who already have a share keep it: changing access or removing
     * is done in the "Shared with" table.
     *
     * @param  array<string, mixed>  $data
     */
    private static function save(CalendarInstance|AddressBook $record, array $data): void
    {
        $excluded = self::excludedIds($record);
        $access = self::accessValue($data['share_access'] ?? null);

        if ($access === null) {
            self::halt(__('Access must be read only or read and write.'));
        }

        $users = [];

        foreach (is_array($data['sharee_ids'] ?? null) ? $data['sharee_ids'] : [] as $item) {
            $id = self::userId($item);
            $user = $id === null ? null : User::query()->find($id);

            if (! $user instanceof User || in_array($id, $excluded, true)) {
                self::halt(__('That user cannot be chosen.'));
            }

            $users[$id] = $user;
        }

        if ($users === []) {
            self::halt(__('That user cannot be chosen.'));
        }

        $sharing = app(SharingService::class);

        try {
            foreach ($users as $user) {
                $sharing->share($record, $user, $access);
            }
        } catch (InvalidArgumentException $exception) {
            self::halt(PanelErrorTranslator::translate($exception->getMessage()));
        } catch (UniqueConstraintViolationException) {
            self::halt(__('Could not save the shares'));
        }

        Notification::make()
            ->success()
            ->title(__('Shares saved'))
            ->send();
    }

    /**
     * @return array<int|string, string>
     */
    private static function searchUsers(CalendarInstance|AddressBook|null $record, ?string $search): array
    {
        if (! $record instanceof CalendarInstance && ! $record instanceof AddressBook) {
            return [];
        }

        $search = trim((string) $search);
        $excluded = self::excludedIds($record);

        $users = User::query()
            ->when($excluded !== [], fn (Builder $query): Builder => $query->whereKeyNot($excluded))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $like = '%'.$search.'%';
                    $query->whereLike('username', $like)
                        ->orWhereLike('display_name', $like)
                        ->orWhereLike('email', $like);
                });
            })
            ->orderBy('username')
            ->orderBy('id')
            ->limit(50)
            ->get(['id', 'username', 'display_name', 'email']);

        $options = [];

        foreach ($users as $user) {
            $options[(int) $user->getKey()] = self::userLabel($user);
        }

        return $options;
    }

    /**
     * The owner and the current sharees are not offered again.
     *
     * @return list<int>
     */
    private static function excludedIds(CalendarInstance|AddressBook $record): array
    {
        $principals = [(string) $record->getAttribute('principaluri')];

        foreach (app(SharingService::class)->sharees($record) as $share) {
            $principals[] = (string) $share->getAttribute('principaluri');
        }

        $usernames = [];

        foreach ($principals as $principal) {
            $username = self::username($principal);

            if ($username !== '') {
                $usernames[] = $username;
            }
        }

        if ($usernames === []) {
            return [];
        }

        return User::query()
            ->whereIn('username', array_values(array_unique($usernames)))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private static function collectionFrom(Select $component): CalendarInstance|AddressBook|null
    {
        $record = $component->getRecord();

        return $record instanceof CalendarInstance || $record instanceof AddressBook ? $record : null;
    }

    /**
     * @return list<string>
     */
    private static function ownedLabels(User $user): array
    {
        $sharing = app(SharingService::class);
        $labels = [];

        foreach ([...$user->calendarInstances()->orderBy('id')->get(), ...$user->addressBooks()->orderBy('id')->get()] as $collection) {
            $sharees = $sharing->sharees($collection);

            if ($sharees->isEmpty()) {
                continue;
            }

            $name = self::collectionName($collection->getAttribute('displayname'), $collection->getAttribute('uri'));
            $who = self::names($sharees);
            $labels[] = $collection instanceof CalendarInstance
                ? __('Calendar ":name" — :who', ['name' => $name, 'who' => $who])
                : __('Address book ":name" — :who', ['name' => $name, 'who' => $who]);
        }

        return $labels;
    }

    /**
     * @return list<string>
     */
    private static function receivedLabels(User $user): array
    {
        $sharing = app(SharingService::class);
        $calendars = $sharing->receivedCalendarShares($user);
        $books = $sharing->receivedAddressBookShares($user);
        $principals = [];

        foreach ($calendars as $share) {
            $owner = $share->ownerInstance;

            if ($owner instanceof CalendarInstance) {
                $principals[] = (string) $owner->getAttribute('principaluri');
            }
        }

        foreach ($books as $share) {
            $book = $share->addressBook;

            if ($book instanceof AddressBook) {
                $principals[] = (string) $book->getAttribute('principaluri');
            }
        }

        $users = self::usersByUsername($principals);
        $labels = [];

        foreach ($calendars as $share) {
            $owner = $share->ownerInstance;
            $labels[] = __('Calendar ":name" — :who', [
                'name' => self::collectionName(
                    $owner instanceof CalendarInstance ? $owner->getAttribute('displayname') : null,
                    $owner instanceof CalendarInstance ? $owner->getAttribute('uri') : $share->getAttribute('uri'),
                ),
                'who' => self::person($users, $owner instanceof CalendarInstance ? (string) $owner->getAttribute('principaluri') : ''),
            ]);
        }

        foreach ($books as $share) {
            $book = $share->addressBook;
            $labels[] = __('Address book ":name" — :who', [
                'name' => self::collectionName(
                    $book instanceof AddressBook ? $book->getAttribute('displayname') : null,
                    $book instanceof AddressBook ? $book->getAttribute('uri') : $share->getAttribute('uri'),
                ),
                'who' => self::person($users, $book instanceof AddressBook ? (string) $book->getAttribute('principaluri') : ''),
            ]);
        }

        return $labels;
    }

    /**
     * @param  list<string>  $labels
     */
    private static function joinLabels(array $labels): string
    {
        return $labels === [] ? __('none') : implode('; ', $labels);
    }

    /**
     * @param  iterable<mixed, Model>  $shares
     */
    private static function names(iterable $shares): string
    {
        $users = self::usersByUsername(self::principals($shares));

        return collect($shares)->map(function (Model $share) use ($users): string {
            return self::person($users, (string) $share->getAttribute('principaluri'));
        })->implode(', ');
    }

    /**
     * @param  iterable<mixed, Model>  $shares
     * @return list<string>
     */
    public static function principals(iterable $shares): array
    {
        $principals = [];

        foreach ($shares as $share) {
            $principals[] = (string) $share->getAttribute('principaluri');
        }

        return $principals;
    }

    /**
     * @param  list<string>  $principals
     * @return Collection<string, User>
     */
    public static function usersByUsername(array $principals): Collection
    {
        $usernames = [];

        foreach ($principals as $principal) {
            $username = self::username($principal);

            if ($username !== '') {
                $usernames[] = $username;
            }
        }

        if ($usernames === []) {
            return new Collection;
        }

        return User::query()
            ->whereIn('username', array_values(array_unique($usernames)))
            ->get()
            ->keyBy('username');
    }

    /**
     * @param  Collection<string, User>  $users
     */
    private static function person(Collection $users, string $principal): string
    {
        $username = self::username($principal);
        $user = $username === '' ? null : $users->get($username);

        if ($user instanceof User && $user->display_name !== '') {
            return $user->display_name;
        }

        return $username !== '' ? $username : $principal;
    }

    public static function username(string $principal): string
    {
        $prefix = 'principals/';

        if (! str_starts_with($principal, $prefix)) {
            return '';
        }

        return substr($principal, strlen($prefix));
    }

    private static function collectionName(mixed $display, mixed $uri): string
    {
        $display = is_string($display) ? trim($display) : '';

        if ($display !== '') {
            return $display;
        }

        return is_string($uri) ? $uri : '';
    }

    public static function userLabel(User $user): string
    {
        return __(':name (:username) — :email', [
            'name' => $user->display_name,
            'username' => $user->username,
            'email' => $user->email,
        ]);
    }

    /**
     * @return array<int, string>
     */
    public static function accessOptions(): array
    {
        return [
            SharingService::READ => __('Read only'),
            SharingService::READ_WRITE => __('Read and write'),
        ];
    }

    /**
     * Filament's hint slot is aligned to the end of the row, and its CSS draws
     * every SVG as a block, so the icon is placed here with an inline style.
     * An HTML label is not used in validation messages; validationAttribute()
     * names the field there.
     */
    public static function accessLabel(): HtmlString
    {
        $icon = \Filament\Support\generate_icon_html(
            Heroicon::OutlinedInformationCircle,
            attributes: (new FilamentComponentAttributeBag([
                'x-tooltip' => '{ content: '.Js::from(self::accessHelp()).', theme: $store.theme }',
                // The help is only in the tooltip: reachable by keyboard and
                // read by screen readers too.
                'tabindex' => '0',
                'role' => 'img',
                'aria-label' => self::accessHelp(),
                'style' => 'display:inline-block;width:1rem;height:1rem;vertical-align:-0.125em',
            ]))->class([
                'fi-sc-icon',
                'text-gray-400',
                'dark:text-gray-500',
            ]),
            size: IconSize::Small,
        );

        return new HtmlString(
            '<span style="display:inline-flex;align-items:center;gap:0.25rem;white-space:nowrap">'
            .e(__('Access'))
            .($icon?->toHtml() ?? '')
            .'</span>'
        );
    }

    private static function accessHelp(): string
    {
        return __("Read and write lets the sharee change and delete the owner's items for everyone. After the access changes, a client may need the calendar or address book set or added again. Thunderbird keeps its own Read-only flag.");
    }

    private static function accessRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (self::accessValue($value) === null) {
                $fail(__('Access must be read only or read and write.'));
            }
        };
    }

    private static function userId(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9]\d*$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    public static function accessValue(mixed $value): ?int
    {
        $access = is_int($value) ? $value : (is_string($value) && preg_match('/^\d+$/', $value) === 1 ? (int) $value : null);

        if ($access === SharingService::READ || $access === SharingService::READ_WRITE) {
            return $access;
        }

        return null;
    }

    private static function halt(string $body): never
    {
        Notification::make()
            ->danger()
            ->title(__('Could not save the shares'))
            ->body($body)
            ->send();

        throw new Halt;
    }
}
