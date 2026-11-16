<?php

namespace App\Providers\Filament;

use App\Http\Middleware\AuthenticateAdmin;
use App\Http\Middleware\PanelIdleTimeout;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetPanelLocale;
use App\Models\User;
use App\Support\LocaleCatalog;
use App\Support\PanelSessions;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Livewire\Livewire;

/**
 * What the admin panel and the user portal share: the colors, the
 * middleware stack with the per-panel idle lifetime, the language action of
 * the user menu, the session timeout script and the notification duration.
 */
final class PanelSetup
{
    public static function apply(Panel $panel): Panel
    {
        $id = $panel->getId();

        return $panel
            ->colors([
                'primary' => Color::Blue,
                'info' => Color::Sky,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
                'gray' => Color::Gray,
            ])
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => self::sessionTimeout())
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                SetPanelLocale::class,
                AuthenticateSession::class,
                PanelIdleTimeout::class.':'.$id,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SecurityHeaders::class,
            ])
            // Livewire compares persistent middleware without parameters.
            ->persistentMiddleware([PanelIdleTimeout::class])
            // The admin panel shows Access denied to a signed-in user who is not an admin.
            ->authMiddleware([
                $id === 'admin' ? AuthenticateAdmin::class : Authenticate::class,
            ], isPersistent: true);
    }

    /**
     * Success and info messages close after the configured time (0 keeps
     * them); errors and warnings stay until closed. An explicit
     * ->persistent() or ->duration() on a message still wins.
     */
    public static function configureNotifications(): void
    {
        Notification::configureUsing(function (Notification $notification): void {
            $notification->duration(function () use ($notification): int|string {
                $seconds = (int) config('nexusdav.notifications.seconds', 6);

                if ($seconds <= 0 || in_array($notification->getStatus(), ['danger', 'warning'], true)) {
                    return 'persistent';
                }

                return $seconds * 1000;
            });
        });
    }

    /**
     * The script that returns an idle user to the panel's sign-in page when
     * the panel's own lifetime (Configuration, Sessions) has passed.
     * Signed-in pages only.
     */
    public static function sessionTimeout(): string
    {
        if (! Filament::auth()->check()) {
            return '';
        }

        $panel = (string) Filament::getCurrentOrDefaultPanel()->getId();

        return view('filament.session-timeout', [
            'lifetime' => PanelSessions::lifetime($panel),
            'storageKey' => 'nexusdav.'.$panel.'.activity',
            'loginUrl' => Filament::getLoginUrl(['timeout' => 1]),
            'logoutUrl' => Filament::getLogoutUrl(),
        ])->render();
    }

    /**
     * The user's own language, saved in users.locale. "Server default"
     * clears it. The page is reloaded so the whole panel switches at once.
     */
    public static function languageAction(): Action
    {
        return Action::make('language')
            ->label(fn (): string => __('Language: :language', ['language' => self::currentLanguageName()]))
            ->icon(Heroicon::OutlinedLanguage)
            ->schema([
                Select::make('locale')
                    ->label(fn (): string => __('Language'))
                    ->options(fn (): array => app(LocaleCatalog::class)->all())
                    ->placeholder(fn (): string => __('Server default (:language)', ['language' => self::languageName(app(LocaleCatalog::class)->default())]))
                    ->in(fn (): array => array_keys(app(LocaleCatalog::class)->all()))
                    ->nullable()
                    ->native(false),
            ])
            ->fillForm(function (): array {
                $locale = Filament::auth()->user()?->locale;

                return ['locale' => app(LocaleCatalog::class)->supports($locale) ? $locale : null];
            })
            ->action(function (array $data, Action $action): void {
                $user = Filament::auth()->user();

                if (! $user instanceof User) {
                    return;
                }

                $catalog = app(LocaleCatalog::class);
                $locale = $catalog->supports($data['locale'] ?? null) ? (string) $data['locale'] : null;

                $user->forceFill(['locale' => $locale])->save();
                app()->setLocale($user->resolvedLocale());

                Notification::make()
                    ->success()
                    ->title(__('Language saved'))
                    ->send();

                $panel = rtrim((string) Filament::getUrl(), '/');
                // The page the menu was opened on; Livewire knows it during an update request.
                $previous = Livewire::originalUrl();
                $samePanel = $previous === $panel || str_starts_with($previous, $panel.'/') || str_starts_with($previous, $panel.'?');

                $action->redirect($samePanel ? $previous : $panel, navigate: false);
            });
    }

    /**
     * The language the signed-in user sees, with "(server default)" when it
     * is not their own choice, so the menu says why the panel is in it.
     */
    private static function currentLanguageName(): string
    {
        $user = Filament::auth()->user();
        $catalog = app(LocaleCatalog::class);

        if ($user instanceof User && $catalog->supports($user->locale)) {
            return self::languageName((string) $user->locale);
        }

        return __('Server default (:language)', ['language' => self::languageName($catalog->default())]);
    }

    private static function languageName(string $locale): string
    {
        return app(LocaleCatalog::class)->all()[$locale] ?? $locale;
    }
}
