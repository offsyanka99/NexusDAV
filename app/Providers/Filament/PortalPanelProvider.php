<?php

namespace App\Providers\Filament;

use App\Filament\Auth\PortalLogin;
use App\Filament\Portal\Account;
use App\Filament\Portal\AppPasswords;
use App\Http\Middleware\RequirePortal;
use App\Models\User;
use App\Portal\PortalApps;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Icons\Heroicon;

/**
 * The user portal: a second panel at the root of the server, beside /admin.
 * Filament's default panel stays admin (console, queued notifications and
 * tests use the default panel). Every portal URL is a top-level slug, so it
 * must never be one of RESERVED_SLUGS.
 */
class PortalPanelProvider extends PanelProvider
{
    /**
     * First path segments that belong to the server, never to a portal page.
     */
    public const RESERVED_SLUGS = ['admin', 'dav', 'health', 'up', '.well-known', 'livewire*', 'filament', 'build', 'css', 'js', 'fonts', 'storage'];

    public function panel(Panel $panel): Panel
    {
        return PanelSetup::apply(
            $panel
                ->id('portal')
                ->path('')
                // Before the session: with the switch off nothing of the portal runs.
                ->middleware([RequirePortal::class], isPersistent: true)
                ->login(PortalLogin::class)
                ->brandName('NexusDAV'),
        )
            ->topNavigation()
            ->viteTheme('resources/css/filament/portal/theme.css')
            ->discoverPages(in: app_path('Filament/Portal'), for: 'App\Filament\Portal')
            // The apps' pages (PortalApps), wherever they are; the navigation follows them.
            ->pages(app(PortalApps::class)->pages())
            ->userMenuItems([
                Action::make('account')
                    ->label(fn (): string => __('Account'))
                    ->icon(Heroicon::OutlinedUserCircle)
                    ->url(fn (): string => Account::getUrl()),
                Action::make('appPasswords')
                    ->label(fn (): string => __('App passwords'))
                    ->icon(Heroicon::OutlinedKey)
                    ->url(fn (): string => AppPasswords::getUrl()),
                PanelSetup::languageAction(),
                Action::make('admin')
                    ->label(fn (): string => __('Admin panel'))
                    ->icon(Heroicon::OutlinedWrenchScrewdriver)
                    ->url(fn (): string => (string) Filament::getPanel('admin')->getUrl())
                    ->visible(function (): bool {
                        $user = Filament::auth()->user();

                        return $user instanceof User && $user->canAccessPanel(Filament::getPanel('admin'));
                    }),
            ])
            ->widgets([]);
    }
}
