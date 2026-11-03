<?php

namespace App\Filament\Pages;

use App\Services\SettingsService;
use App\Support\LocaleCatalog;
use BackedEnum;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Server-wide preferences: time, rate limiting, sessions and diagnostics.
 * DAV services are on the Settings page.
 */
class Configuration extends SettingsPage
{
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
                                ->helperText(__('Used on the login page and for admins who have not chosen a language in the user menu.')),
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
                            ->helperText(__('Minutes without activity before an admin is signed out.')), 'session_lifetime'),
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
                Section::make(__('Diagnostics'))
                    ->schema([
                        $this->lockable($this->number('diagnostics_retention_days', __('Keep failed logins and DAV errors for'))
                            ->suffix(__('days')), 'diagnostics_retention_days'),
                    ]),
            ])
            ->statePath('data');
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
