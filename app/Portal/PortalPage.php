<?php

namespace App\Portal;

use App\Services\Portal\PortalErrors;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Throwable;

/**
 * Base of every user portal page. Access, the navigation entry and the
 * title come from the page's app in the registry (PortalApps): a page of an
 * app whose switch is off answers 404, as an unknown address would. A page
 * of no app (Account, App passwords) is open to every portal user.
 * Dates are shown with PortalTime, in the user's time zone.
 */
abstract class PortalPage extends Page
{
    public static function portalApp(): ?PortalApp
    {
        return app(PortalApps::class)->forPage(static::class);
    }

    public static function canAccess(): bool
    {
        return static::portalApp()?->isVisible() ?? true;
    }

    public function mountCanAuthorizeAccess(): void
    {
        abort_unless(static::canAccess(), 404);
    }

    public function hydrateCanAuthorizeAccess(): void
    {
        abort_unless(static::canAccess(), 404);
    }

    /**
     * Only an app's first page is in the navigation; the other pages are
     * reached from it.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return static::portalApp()?->homePage() === static::class;
    }

    public static function getNavigationLabel(): string|Htmlable
    {
        return static::portalApp()?->label() ?? parent::getNavigationLabel();
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return static::portalApp()?->icon() ?? parent::getNavigationIcon();
    }

    public static function getNavigationSort(): ?int
    {
        return static::portalApp()?->sort() ?? parent::getNavigationSort();
    }

    public function getTitle(): string|Htmlable
    {
        return static::$title ?? static::portalApp()?->label() ?? parent::getTitle();
    }

    /**
     * A failure the user should see that is not a store refusal (those go
     * through notifyFailure()). It stays until closed.
     */
    protected function notifyError(string $title, ?string $body = null): void
    {
        Notification::make()
            ->danger()
            ->title($title)
            ->body($body)
            ->send();
    }

    /**
     * A refusal from a store or reader (not found, read-only, changed on
     * another device, ...) as a notification. Anything else is thrown again.
     */
    protected function notifyFailure(Throwable $exception): void
    {
        PortalErrors::send($exception);
    }

    /**
     * The content of an app page that is not built yet.
     */
    protected function placeholder(Schema $schema): Schema
    {
        return $schema->components([
            Text::make(__('This part of the portal is not ready yet. Until then, use a calendar, contacts or files app on your device.')),
        ]);
    }
}
