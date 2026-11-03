<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetAdminLocale;
use App\Models\User;
use App\Support\LocaleCatalog;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Livewire\Livewire;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->brandName('NexusDAV')
            ->colors([
                'primary' => Color::Blue,
                'info' => Color::Sky,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
                'gray' => Color::Gray,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->userMenuItems([
                self::languageAction(),
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            // The Dashboard shows only the widgets discovered above (status,
            // services, counts, needs attention). Filament's defaults are dropped.
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                SetAdminLocale::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SecurityHeaders::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    /**
     * The admin's own language, saved in users.locale. "Server default"
     * clears it. The page is reloaded so the whole panel switches at once.
     */
    private static function languageAction(): Action
    {
        return Action::make('language')
            ->label(fn (): string => __('Language'))
            ->icon(Heroicon::OutlinedLanguage)
            ->schema([
                Select::make('locale')
                    ->label(fn (): string => __('Language'))
                    ->options(fn (): array => app(LocaleCatalog::class)->all())
                    ->placeholder(fn (): string => __('Server default'))
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
}
