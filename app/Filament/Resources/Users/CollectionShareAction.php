<?php

namespace App\Filament\Resources\Users;

use App\Models\AddressBook;
use App\Models\CalendarInstance;
use App\Models\User;
use App\Services\Dav\SharingService;
use App\Support\PanelErrorTranslator;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\IconSize;
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
            ->modalHeading(__('Shared with'))
            ->modalSubmitActionLabel(__('Save'))
            ->fillForm(fn (CalendarInstance|AddressBook $record): array => self::formState($record))
            ->schema([
                Repeater::make('shares')
                    ->hiddenLabel()
                    ->addable(false)
                    ->reorderable(false)
                    ->defaultItems(0)
                    ->columnSpanFull()
                    ->itemLabel(fn (array $state): ?string => is_string($state['sharee'] ?? null) ? $state['sharee'] : null)
                    ->schema([
                        Hidden::make('user_id'),
                        Hidden::make('sharee'),
                        Select::make('access')
                            ->label(self::accessLabel())
                            ->options(self::accessOptions())
                            ->selectablePlaceholder(false)
                            ->required()
                            ->rule(fn (): Closure => self::accessRule()),
                    ])
                    ->deleteAction(fn (Action $action): Action => $action
                        ->label(__('Remove'))
                        ->modalHeading(__('Remove share'))
                        ->modalDescription(__("This share will be removed. The owner's items stay."))
                        ->modalSubmitActionLabel(__('Remove'))
                        ->requiresConfirmation()
                        ->before(function (array $arguments, Repeater $component): void {
                            self::remove($component, $arguments);
                        })),
                Section::make(__('Add a user'))
                    ->compact()
                    ->columnSpanFull()
                    ->schema([
                        self::userSelect(),
                        self::accessSelect(),
                    ]),
            ])
            ->action(function (CalendarInstance|AddressBook $record, array $data): void {
                self::save($record, $data);
            });
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

    /**
     * @return array{shares: list<array{user_id: int, sharee: string, access: int}>, sharee_id: null, share_access: int}
     */
    private static function formState(CalendarInstance|AddressBook $record): array
    {
        $rows = app(SharingService::class)->sharees($record);
        $users = self::usersByUsername(self::principals($rows));
        $shares = [];

        foreach ($rows as $share) {
            $user = $users->get(self::username((string) $share->getAttribute('principaluri')));

            if (! $user instanceof User) {
                continue;
            }

            $shares[] = [
                'user_id' => (int) $user->getKey(),
                'sharee' => self::userLabel($user),
                'access' => (int) $share->getAttribute('access'),
            ];
        }

        return [
            'shares' => $shares,
            'sharee_id' => null,
            'share_access' => SharingService::READ,
        ];
    }

    private static function userSelect(): Select
    {
        return Select::make('sharee_id')
            ->label(__('User'))
            ->searchable()
            ->placeholder(__('Search by username, display name, or email.'))
            ->getSearchResultsUsing(function (Select $component, ?string $search): array {
                return self::searchUsers(self::collectionFrom($component), $search);
            })
            ->getOptionLabelUsing(function (mixed $value): ?string {
                $id = self::userId($value);
                $user = $id === null ? null : User::query()->find($id);

                return $user instanceof User ? self::userLabel($user) : null;
            })
            ->rule(function (Select $component): Closure {
                return function (string $attribute, mixed $value, Closure $fail) use ($component): void {
                    if (blank($value)) {
                        return;
                    }

                    $record = self::collectionFrom($component);
                    $id = self::userId($value);

                    if (! $record instanceof CalendarInstance && ! $record instanceof AddressBook) {
                        $fail(__('That user cannot be chosen.'));

                        return;
                    }

                    $user = $id === null ? null : User::query()->find($id);

                    if (! $user instanceof User || in_array($id, self::excludedIds($record), true)) {
                        $fail(__('That user cannot be chosen.'));
                    }
                };
            });
    }

    private static function accessSelect(): Select
    {
        return Select::make('share_access')
            ->label(self::accessLabel())
            ->options(self::accessOptions())
            ->default(SharingService::READ)
            ->selectablePlaceholder(false)
            ->required(fn (Get $get): bool => filled($get('sharee_id')))
            ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                if (blank($get('sharee_id'))) {
                    return;
                }

                if (self::accessValue($value) === null) {
                    $fail(__('Access must be read only or read and write.'));
                }
            });
    }

    /**
     * Saving updates access and adds a user. It does not remove anyone: a
     * form that failed to refill would otherwise drop every share. Removal
     * is the confirmed delete action.
     *
     * @param  array<string, mixed>  $data
     */
    private static function save(CalendarInstance|AddressBook $record, array $data): void
    {
        $sharing = app(SharingService::class);
        $current = $sharing->sharees($record);
        $users = self::usersByUsername(self::principals($current));
        $sharee = self::chosenSharee($record, $data['sharee_id'] ?? null, $data['share_access'] ?? null);

        /** @var array<int, int> $submitted */
        $submitted = [];

        foreach (is_array($data['shares'] ?? null) ? $data['shares'] : [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = self::userId($item['user_id'] ?? null);

            if ($id === null) {
                continue;
            }

            $access = self::accessValue($item['access'] ?? null);

            if ($access === null) {
                self::halt(__('Access must be read only or read and write.'));
            }

            $submitted[$id] = $access;
        }

        try {
            foreach ($current as $share) {
                $user = $users->get(self::username((string) $share->getAttribute('principaluri')));

                if (! $user instanceof User) {
                    continue;
                }

                $id = (int) $user->getKey();

                if (! array_key_exists($id, $submitted) || $submitted[$id] === (int) $share->getAttribute('access')) {
                    continue;
                }

                $sharing->changeAccess($record, $user, $submitted[$id]);
            }

            if ($sharee !== null) {
                $sharing->share($record, $sharee['user'], $sharee['access']);
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
     * @param  array<string, mixed>  $arguments
     */
    private static function remove(Repeater $component, array $arguments): void
    {
        $record = $component->getRecord();

        if (! $record instanceof CalendarInstance && ! $record instanceof AddressBook) {
            self::halt(__('That collection no longer exists.'));
        }

        $state = $component->getRawState();
        $itemKey = $arguments['item'] ?? null;
        $item = is_array($state) && (is_int($itemKey) || is_string($itemKey)) ? ($state[$itemKey] ?? null) : null;
        $id = is_array($item) ? self::userId($item['user_id'] ?? null) : null;
        $user = $id === null ? null : User::query()->find($id);

        if (! $user instanceof User) {
            self::halt(__('That user no longer exists.'));
        }

        try {
            app(SharingService::class)->unshare($record, $user);
        } catch (InvalidArgumentException $exception) {
            self::halt(PanelErrorTranslator::translate($exception->getMessage()));
        }

        Notification::make()
            ->success()
            ->title(__('Share removed'))
            ->send();
    }

    /**
     * @return array{user: User, access: int}|null
     */
    private static function chosenSharee(CalendarInstance|AddressBook $record, mixed $shareeId, mixed $access): ?array
    {
        if (blank($shareeId)) {
            return null;
        }

        $id = self::userId($shareeId);
        $user = $id === null ? null : User::query()->find($id);

        if (! $user instanceof User || in_array($id, self::excludedIds($record), true)) {
            self::halt(__('That user cannot be chosen.'));
        }

        $resolved = self::accessValue($access);

        if ($resolved === null) {
            self::halt(__('Access must be read only or read and write.'));
        }

        return ['user' => $user, 'access' => $resolved];
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
    private static function principals(iterable $shares): array
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
    private static function usersByUsername(array $principals): Collection
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

    private static function username(string $principal): string
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

    private static function userLabel(User $user): string
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
    private static function accessOptions(): array
    {
        return [
            SharingService::READ => __('Read only'),
            SharingService::READ_WRITE => __('Read and write'),
        ];
    }

    /**
     * Filament's hint slot is aligned to the end of the row, and its CSS draws
     * every SVG as a block, so the icon is placed here with an inline style.
     */
    private static function accessLabel(): HtmlString
    {
        $icon = \Filament\Support\generate_icon_html(
            Heroicon::OutlinedInformationCircle,
            attributes: (new FilamentComponentAttributeBag([
                'x-tooltip' => '{ content: '.Js::from(self::accessHelp()).', theme: $store.theme }',
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
        return __("Read and write lets the sharee change and delete the owner's items for everyone. A client may need the book set or added again after the access changes. Thunderbird keeps its own Read-only flag.");
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

    private static function accessValue(mixed $value): ?int
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
