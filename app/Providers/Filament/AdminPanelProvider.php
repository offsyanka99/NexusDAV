<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Services\Portal\PortalConfig;
use Filament\Actions\Action;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Icons\Heroicon;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return PanelSetup::apply(
            $panel
                ->default()
                ->id('admin')
                ->path('admin')
                ->login(Login::class)
                ->brandName('NexusDAV'),
        )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->userMenuItems([
                PanelSetup::languageAction(),
                Action::make('portal')
                    ->label(fn (): string => __('User portal'))
                    ->icon(Heroicon::OutlinedHome)
                    ->url(fn (): string => url('/'))
                    ->visible(fn (): bool => PortalConfig::enabled()),
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            // The Dashboard shows only the widgets discovered above (status,
            // services, counts, needs attention). Filament's defaults are dropped.
            ->widgets([]);
    }
}
