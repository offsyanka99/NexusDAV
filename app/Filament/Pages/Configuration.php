<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\FactoryReset;
use App\Services\FileUsage;
use App\Services\SettingsService;
use App\Services\SettingsTransfer;
use App\Support\LocaleCatalog;
use App\Support\PanelErrorTranslator;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Number;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Server-wide preferences: time, rate limiting, sessions and diagnostics.
 * DAV services are on the Settings page. Below the form, the settings of
 * both pages are exported to and imported from a JSON file, and the Danger
 * Zone resets the server to a fresh install (FactoryReset) after two
 * confirmations.
 */
class Configuration extends SettingsPage
{
    public const RESET_ATTEMPTS = 5;

    /**
     * The settings of the uploaded file, between the upload and Apply.
     *
     * @var array<string, mixed>|null
     */
    public ?array $importedSettings = null;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'configuration';

    public static function getNavigationLabel(): string
    {
        return __('Configuration');
    }

    public function getTitle(): string
    {
        return __('Configuration');
    }

    /**
     * @return list<string>
     */
    protected function settingKeys(): array
    {
        return [
            'timezone',
            'default_locale',
            'time_format',
            'week_start',
            'rate_limit_max_attempts',
            'rate_limit_per_address',
            'rate_limit_decay_seconds',
            'session_lifetime',
            'session_secure_cookie',
            'diagnostics_retention_days',
            'notification_seconds',
        ];
    }

    protected function usesStringOptions(string $key): bool
    {
        return $key === 'week_start';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Language'))
                    ->schema([
                        $this->lockable(
                            Select::make('default_locale')
                                ->label(__('Server default language'))
                                ->options(app(LocaleCatalog::class)->all())
                                ->required()
                                ->selectablePlaceholder(false)
                                ->helperText(__('Used on the login page and for admins who have not chosen a language in the user menu. A language chosen in the user menu always wins.')),
                            'default_locale',
                        ),
                    ]),
                Section::make(__('Time'))
                    ->columns(3)
                    ->schema([
                        $this->lockable(
                            Select::make('timezone')
                                ->label(__('Server time zone'))
                                ->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                                ->searchable()
                                ->required()
                                ->helperText(__('Used for users without their own time zone, for new calendars, and to show times in this panel.')),
                            'timezone',
                        ),
                        $this->lockable(
                            Select::make('time_format')
                                ->label(__('Time format'))
                                ->options(['24' => __('24-hour'), '12' => __('12-hour')])
                                ->required()
                                ->selectablePlaceholder(false),
                            'time_format',
                        ),
                        $this->lockable(
                            Select::make('week_start')
                                ->label(__('Week starts on'))
                                ->options([
                                    '1' => __('Monday'),
                                    '2' => __('Tuesday'),
                                    '3' => __('Wednesday'),
                                    '4' => __('Thursday'),
                                    '5' => __('Friday'),
                                    '6' => __('Saturday'),
                                    '0' => __('Sunday'),
                                ])
                                ->required()
                                ->selectablePlaceholder(false),
                            'week_start',
                        ),
                    ]),
                Section::make(__('Rate limiting'))
                    ->description(__('Applies to the admin login and to DAV logins. Counted per client address.'))
                    ->columns(3)
                    ->schema([
                        $this->lockable($this->number('rate_limit_max_attempts', __('Failures per username'))
                            ->helperText(__('Per address and username, before that login is refused for the window.')), 'rate_limit_max_attempts'),
                        $this->lockable($this->number('rate_limit_per_address', __('Failures per address'))
                            ->helperText(__('Across all usernames. Off when TRUSTED_PROXIES is "*".')), 'rate_limit_per_address'),
                        $this->lockable($this->number('rate_limit_decay_seconds', __('Window'))
                            ->suffix(__('seconds')), 'rate_limit_decay_seconds'),
                    ]),
                Section::make(__('Sessions'))
                    ->columns(2)
                    ->schema([
                        $this->lockable($this->number('session_lifetime', __('Session lifetime'))
                            ->suffix(__('minutes'))
                            ->helperText(__('Minutes without activity before an admin is signed out and the panel returns to the sign-in page. Opening a page, saving and other actions count as activity.')), 'session_lifetime'),
                        $this->lockable(
                            Toggle::make('session_secure_cookie')
                                ->label(__('Secure cookies'))
                                ->helperText(__('Send the session cookie over HTTPS only. Turn this on only while you use the panel over HTTPS, or you will be signed out.'))
                                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                    $current = (bool) app(SettingsService::class)->get('session_secure_cookie');

                                    if ($value && ! $current && ! request()->isSecure()) {
                                        $fail(__('Open the panel over HTTPS before you turn this on.'));
                                    }
                                }),
                            'session_secure_cookie',
                        ),
                    ]),
                Section::make(__('Notifications'))
                    ->schema([
                        $this->lockable($this->number('notification_seconds', __('Close messages after'))
                            ->suffix(__('seconds'))
                            ->helperText(__('Success and info messages close by themselves after this time. 0 keeps them until you close them. Errors and warnings always stay.')), 'notification_seconds'),
                    ]),
                Section::make(__('Diagnostics'))
                    ->schema([
                        $this->lockable($this->number('diagnostics_retention_days', __('Keep failed logins and DAV errors for'))
                            ->suffix(__('days')), 'diagnostics_retention_days'),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * @return list<Component>
     */
    protected function afterForm(): array
    {
        return [
            Section::make(__('Settings backup'))
                ->description(__('A JSON file with the settings of the Settings and Configuration pages. Settings that come from the environment, secrets, users and their data are not in it.'))
                ->schema([
                    Actions::make([
                        Action::make('exportSettings')
                            ->label(__('Export settings'))
                            ->icon(Heroicon::OutlinedArrowDownTray)
                            ->action(fn (): StreamedResponse => $this->exportSettings()),
                        Action::make('importSettings')
                            ->label(__('Import settings'))
                            ->icon(Heroicon::OutlinedArrowUpTray)
                            ->color('gray')
                            ->modalHeading(__('Import settings'))
                            ->modalDescription(__('Choose a settings file exported from NexusDAV. You see what would change before anything is saved.'))
                            ->modalSubmitActionLabel(__('Show changes'))
                            ->schema([
                                FileUpload::make('file')
                                    ->label(__('Settings file'))
                                    ->acceptedFileTypes(['application/json', 'text/plain'])
                                    ->maxSize(SettingsTransfer::MAX_BYTES / 1024)
                                    ->storeFiles(false)
                                    ->required(),
                            ])
                            ->action(function (array $data, Action $action): void {
                                $this->readImport($data['file'] ?? null, $action);
                            }),
                    ])->key('settingsBackup'),
                ]),
            Section::make(__('Danger Zone'))
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->iconColor('danger')
                ->description(__('Reset to Default deletes every other user, all calendars, address books and files, and the saved settings. You keep your account. It cannot be undone.'))
                ->extraAttributes(['class' => 'nexusdav-danger-zone', 'style' => 'box-shadow: 0 0 0 1px var(--danger-600);'])
                ->schema([
                    Actions::make([
                        Action::make('resetToDefault')
                            ->label(__('Reset to Default'))
                            ->icon(Heroicon::OutlinedTrash)
                            ->color('danger')
                            ->modalHeading(__('Reset to Default'))
                            ->modalIcon(Heroicon::OutlinedExclamationTriangle)
                            ->modalIconColor('danger')
                            ->schema(fn (): array => $this->resetWarning())
                            ->modalSubmitActionLabel(__('Continue'))
                            ->extraModalFooterActions([
                                Action::make('exportBeforeReset')
                                    ->label(__('Export settings first'))
                                    ->icon(Heroicon::OutlinedArrowDownTray)
                                    ->color('gray')
                                    ->action(fn (): StreamedResponse => $this->exportSettings()),
                            ])
                            ->action(function (): void {
                                $this->replaceMountedAction('confirmReset');
                            }),
                    ])->key('dangerZone'),
                ]),
        ];
    }

    public function confirmResetAction(): Action
    {
        return Action::make('confirmReset')
            ->modalHeading(__('Reset to Default'))
            ->modalDescription(__('This deletes the data of every user on this server. Type the server name and your password to go on.'))
            ->color('danger')
            ->schema([
                TextInput::make('host')
                    ->label(__('Type :host', ['host' => $this->resetHost()]))
                    ->required()
                    ->live()
                    ->autocomplete(false)
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if ($value !== $this->resetHost()) {
                            $fail(__('Type the server name exactly as shown.'));
                        }
                    }),
                TextInput::make('password')
                    ->label(__('Your password'))
                    ->password()
                    ->required()
                    ->autocomplete('current-password')
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (! $this->resetPasswordAccepted(is_string($value) ? $value : '')) {
                            $fail(RateLimiter::tooManyAttempts($this->resetLimiterKey(), self::RESET_ATTEMPTS)
                                ? __('Too many wrong passwords. Try again in a minute.')
                                : __('The password is not correct.'));
                        }
                    }),
            ])
            ->modalSubmitActionLabel(__('Reset'))
            ->modalSubmitAction(fn (Action $action): Action => $action
                ->color('danger')
                ->disabled(fn (): bool => data_get($this->mountedActions, '0.data.host') !== $this->resetHost()))
            ->action(fn () => $this->resetServer());
    }

    /**
     * @return list<Component>
     */
    private function resetWarning(): array
    {
        $user = Filament::auth()->user();
        $counts = $user instanceof User ? app(FactoryReset::class)->counts($user) : ['users' => 0, 'calendars' => 0, 'address_books' => 0, 'items' => 0];
        $components = DB::table('calendarobjects')->selectRaw('componenttype, count(*) as total')->groupBy('componenttype')->pluck('total', 'componenttype');
        $last = app(FileUsage::class)->last();
        $files = $last === null
            ? __('Not counted yet')
            : ($last['complete'] ? Number::fileSize($last['bytes'], 0, 2) : __('At least :size', ['size' => Number::fileSize($last['bytes'], 0, 2)]));

        $deleted = [
            'users' => [__('Other users'), $counts['users']],
            'calendars' => [__('Calendars'), $counts['calendars']],
            'address_books' => [__('Address books'), $counts['address_books']],
            'events' => [__('Events'), (int) ($components['VEVENT'] ?? 0)],
            'tasks' => [__('Tasks'), (int) ($components['VTODO'] ?? 0)],
            'notes' => [__('Notes'), (int) ($components['VJOURNAL'] ?? 0)],
            'contacts' => [__('Contacts'), DB::table('cards')->count()],
        ];
        $entries = [];

        foreach ($deleted as $key => [$label, $count]) {
            $entries[] = TextEntry::make('deleted_'.$key)->label($label)->inlineLabel()->state(Number::format($count));
        }

        $entries[] = TextEntry::make('deleted_files')->label(__('Files storage'))->inlineLabel()->state($files);

        return [
            Section::make(__('Deleted'))
                ->description(__('Also every app password (yours too), shares, push subscriptions, diagnostics, saved settings, queued jobs and your other sessions.'))
                ->schema($entries),
            Section::make(__('Kept'))
                ->schema([
                    Text::make(__('Your account and password, settings from the environment, APP_KEY, and the container log. You get a new default calendar and address book, and stay signed in.')),
                ]),
        ];
    }

    private function resetServer(): void
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return;
        }

        app(FactoryReset::class)->run($user, (string) session()->getId());

        Notification::make()
            ->success()
            ->title(__('The server was reset to its defaults.'))
            ->persistent()
            ->send();

        $this->redirect(Filament::getUrl(), navigate: false);
    }

    private function resetHost(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : 'localhost';
    }

    private function resetLimiterKey(): string
    {
        $user = Filament::auth()->user();

        return 'factory-reset:'.($user instanceof User ? $user->getKey() : 'none');
    }

    /**
     * Five wrong passwords per minute per admin, each logged as a warning.
     */
    private function resetPasswordAccepted(string $password): bool
    {
        $user = Filament::auth()->user();
        $key = $this->resetLimiterKey();

        if (! $user instanceof User || RateLimiter::tooManyAttempts($key, self::RESET_ATTEMPTS)) {
            return false;
        }

        if ($password !== '' && Hash::check($password, (string) $user->password)) {
            RateLimiter::clear($key);

            return true;
        }

        RateLimiter::hit($key, 60);
        Log::warning('Factory reset refused: wrong password', ['admin' => $user->username]);

        return false;
    }

    public function applyImportAction(): Action
    {
        return Action::make('applyImport')
            ->modalHeading(__('Import settings'))
            ->schema(fn (): array => $this->importPreview())
            ->modalSubmitActionLabel(__('Apply'))
            ->modalSubmitAction(fn (Action $action): Action|false => $this->importPlan()['changes'] === [] ? false : $action)
            ->action(fn () => $this->applyImport());
    }

    private function exportSettings(): StreamedResponse
    {
        $transfer = app(SettingsTransfer::class);
        $export = $transfer->export();
        $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

        Log::info('Settings exported', ['admin' => $this->adminName(), 'settings' => count($export['settings'])]);

        return response()->streamDownload(static function () use ($json): void {
            echo $json;
        }, $transfer->fileName(), ['Content-Type' => 'application/json; charset=utf-8']);
    }

    private function readImport(mixed $file, Action $action): void
    {
        if (is_array($file)) {
            $file = reset($file);
        }

        $contents = $file instanceof UploadedFile ? (string) file_get_contents($file->getRealPath()) : '';

        try {
            $this->importedSettings = app(SettingsTransfer::class)->parse($contents);
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->danger()
                ->title(__('Could not import'))
                ->body(PanelErrorTranslator::translate($exception->getMessage()))
                ->send();

            $action->halt();
        }

        $this->replaceMountedAction('applyImport');
    }

    /**
     * @return array{changes: array<string, mixed>, same: list<string>, skipped: array<string, string>}
     */
    private function importPlan(): array
    {
        return app(SettingsTransfer::class)->plan($this->importedSettings ?? [], request()->isSecure());
    }

    /**
     * @return list<Component>
     */
    private function importPreview(): array
    {
        $transfer = app(SettingsTransfer::class);
        $settings = app(SettingsService::class);
        $plan = $this->importPlan();
        $components = [];

        if ($plan['changes'] === []) {
            $components[] = Text::make(__('Nothing to change: this server already has these settings.'));
        } else {
            $entries = [];

            foreach ($plan['changes'] as $key => $value) {
                $entries[] = TextEntry::make('change_'.$key)
                    ->label($transfer->label($key))
                    ->inlineLabel()
                    ->state(__(':from → :to', [
                        'from' => $transfer->display($key, $settings->isSaved($key) || $settings->defaultValue($key) !== null ? $settings->get($key) : null),
                        'to' => $transfer->display($key, $value),
                    ]));
            }

            $components[] = Section::make(__('Changes'))->schema($entries);
        }

        if ($plan['same'] !== []) {
            $components[] = Text::make(trans_choice(':count setting is already the same.|:count settings are already the same.', count($plan['same'])));
        }

        if ($plan['skipped'] !== []) {
            $entries = [];

            foreach ($plan['skipped'] as $key => $reason) {
                $entries[] = TextEntry::make('skipped_'.md5($key))
                    ->label($transfer->label($key))
                    ->inlineLabel()
                    ->state($transfer->reason($reason));
            }

            $components[] = Section::make(__('Not imported'))->schema($entries);
        }

        return $components;
    }

    private function applyImport(): void
    {
        $localeBefore = $this->panelLocale();

        try {
            $changed = app(SettingsTransfer::class)->import($this->importedSettings ?? [], request()->isSecure());
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->danger()
                ->title(__('Could not import'))
                ->body(PanelErrorTranslator::translate($exception->getMessage()))
                ->send();

            return;
        }

        $this->importedSettings = null;
        Log::notice('Settings imported', ['admin' => $this->adminName(), 'settings' => $changed]);
        $this->fillForm();

        $localeAfter = $this->panelLocale();

        if ($localeAfter !== $localeBefore) {
            app()->setLocale($localeAfter);
        }

        Notification::make()
            ->success()
            ->title(__('Settings imported'))
            ->body(trans_choice(':count setting changed.|:count settings changed.', count($changed)).' '.__('The change applies from the next request. No restart is needed.'))
            ->send();

        if ($localeAfter !== $localeBefore) {
            $this->redirect(static::getUrl(), navigate: false);
        }
    }

    private function adminName(): ?string
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user->username : null;
    }

    private function number(string $key, string $label): TextInput
    {
        $definition = SettingsService::DEFINITIONS[$key];

        return TextInput::make($key)
            ->label($label)
            ->integer()
            ->required()
            ->minValue($definition['min'] ?? null)
            ->maxValue($definition['max'] ?? null);
    }
}
