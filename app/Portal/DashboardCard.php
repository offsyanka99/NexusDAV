<?php

namespace App\Portal;

use Filament\Widgets\Widget;
use Illuminate\Contracts\Support\Htmlable;

/**
 * A card on the portal Dashboard. An app lists its cards in
 * PortalApp::cards(); the Dashboard shows the cards of the visible apps in
 * app order, so a card is never discovered on its own.
 */
abstract class DashboardCard extends Widget
{
    protected static bool $isDiscovered = false;

    protected string $view = 'filament.portal.dashboard-card';

    abstract public function heading(): string;

    /**
     * The card's content. Dates go through PortalTime.
     */
    abstract public function body(): Htmlable|string;

    /**
     * Where the card's heading links to: its app's first page.
     */
    public function url(): ?string
    {
        return static::portalApp()?->homePage()::getUrl();
    }

    public static function portalApp(): ?PortalApp
    {
        return app(PortalApps::class)->forCard(static::class);
    }

    public static function canView(): bool
    {
        return static::portalApp()?->isVisible() ?? true;
    }
}
