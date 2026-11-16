<?php

namespace App\Filament\Portal;

use App\Models\DiagnosticEvent;
use App\Models\User;
use App\Portal\PortalPage;
use App\Services\AuthThrottle;
use App\Services\Portal\PortalLog;
use App\Services\UserService;
use App\Support\PanelErrorTranslator;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * The signed-in user's own settings: time zone, week numbers, and the main
 * password. Every action reads the user from the session again, never from
 * the page's state.
 *
 * @property-read Schema $form
 */
class Account extends PortalPage
{
    protected static ?string $slug = 'account';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function getTitle(): string
    {
        return __('Account');
    }

    public function mount(): void
    {
        $user = $this->user();

        $this->form->fill([
            'timezone' => $user->timezone,
            'show_week_numbers' => $user->show_week_numbers,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $zones = timezone_identifiers_list();

        return $schema
            ->components([
                Section::make(__('Time'))
                    ->schema([
                        Select::make('timezone')
                            ->label(__('Time zone'))
                            ->options(array_combine($zones, $zones))
                            ->searchable()
                            ->nullable()
                            ->in($zones)
                            ->placeholder(fn (): string => __('Server default (:zone)', ['zone' => (string) config('nexusdav.default_timezone', 'UTC')]))
                            ->helperText(__("The portal shows times in this zone. Empty: the server's zone.")),
                        Toggle::make('show_week_numbers')
                            ->label(__('Show week numbers'))
                            ->helperText(__('ISO week numbers in the calendar.')),
                    ]),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label(__('Save'))
                            ->submit('save')
                            ->keyBindings(['mod+s']),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $user = $this->user();
        $timezone = $state['timezone'] ?? null;

        $user->forceFill([
            'timezone' => is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : null,
            'show_week_numbers' => (bool) ($state['show_week_numbers'] ?? false),
        ])->save();

        PortalLog::info('Portal account settings saved', ['username' => $user->username]);

        Notification::make()
            ->success()
            ->title(__('Saved'))
            ->send();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('changePassword')
                ->label(__('Change password'))
                ->icon(Heroicon::OutlinedKey)
                ->modalDescription(__('Your other sessions are signed out. Devices that sync with an app password are not affected.'))
                ->schema([
                    TextInput::make('current_password')
                        ->label(__('Current password'))
                        ->password()
                        ->revealable()
                        ->required()
                        ->autocomplete('current-password')
                        ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            if (! $this->currentPasswordMatches(is_string($value) ? $value : '')) {
                                $fail(__('The current password is not correct.'));
                            }
                        }),
                    TextInput::make('password')
                        ->label(__('New password'))
                        ->password()
                        ->revealable()
                        ->required()
                        ->minLength(UserService::MIN_PASSWORD_LENGTH)
                        ->confirmed()
                        ->autocomplete('new-password')
                        ->helperText(__('At least :count characters.', ['count' => UserService::MIN_PASSWORD_LENGTH])),
                    TextInput::make('password_confirmation')
                        ->label(__('Confirm password'))
                        ->password()
                        ->revealable()
                        ->required()
                        ->dehydrated(false)
                        ->autocomplete('new-password'),
                ])
                ->action(function (array $data): void {
                    $this->changePassword((string) ($data['password'] ?? ''));
                }),
        ];
    }

    private function changePassword(#[SensitiveParameter] string $password): void
    {
        $user = $this->user();

        try {
            app(UserService::class)->update($user, ['password' => $password]);
        } catch (InvalidArgumentException $exception) {
            $this->notifyError(__('Could not change the password'), PanelErrorTranslator::translate($exception->getMessage()));

            throw new Halt;
        }

        $user->refresh();
        $guard = Filament::auth();

        // This session stays signed in; the others no longer match the
        // password (AuthenticateSession) and their rows are removed now.
        if (method_exists($guard, 'hashPasswordForCookie')) {
            session()->put('password_hash_'.Filament::getAuthGuard(), $guard->hashPasswordForCookie($user->getAuthPassword()));
        }

        if (config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->where('id', '!=', session()->getId())
                ->delete();
        }

        PortalLog::info('Portal password changed', ['username' => $user->username]);

        Notification::make()
            ->success()
            ->title(__('Password changed'))
            ->body(__('Your other sessions are signed out.'))
            ->send();
    }

    /**
     * Wrong answers count toward the sign-in limit of this account, so the
     * form cannot be used to guess the password.
     */
    private function currentPasswordMatches(#[SensitiveParameter] string $password): bool
    {
        $user = $this->user();
        $throttle = app(AuthThrottle::class);

        if ($throttle->tooMany(DiagnosticEvent::SOURCE_PORTAL, $user->username)) {
            return false;
        }

        if (Hash::check($password, $user->getAuthPassword())) {
            return true;
        }

        $throttle->hit(DiagnosticEvent::SOURCE_PORTAL, $user->username);

        return false;
    }

    private function user(): User
    {
        $user = Filament::auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
