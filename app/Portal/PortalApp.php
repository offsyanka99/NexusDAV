<?php

namespace App\Portal;

use App\Services\SettingsService;
use BackedEnum;

/**
 * One app of the user portal (Dashboard, Calendar, Tasks, ...). An app is
 * added by one subclass in app/Portal/Apps and its pages: the navigation,
 * page access and the Dashboard cards follow the registry (PortalApps),
 * with no change to the panel provider.
 */
abstract class PortalApp
{
    abstract public function id(): string;

    abstract public function label(): string;

    abstract public function icon(): string|BackedEnum;

    /**
     * Position in the navigation, lowest first.
     */
    abstract public function sort(): int;

    /**
     * The pages of the app. The first one is the app's entry in the navigation.
     *
     * @return non-empty-list<class-string<PortalPage>>
     */
    abstract public function pages(): array;

    /**
     * Settings (SettingsService keys) that must all be on for the app to be
     * shown. While one is off, the app is left out of the navigation and the
     * Dashboard, and its pages answer 404.
     *
     * @return list<string>
     */
    public function switches(): array
    {
        return [];
    }

    /**
     * Cards the app adds to the Dashboard, in order.
     *
     * @return list<class-string<DashboardCard>>
     */
    public function cards(): array
    {
        return [];
    }

    public function isVisible(): bool
    {
        $settings = app(SettingsService::class);

        foreach ($this->switches() as $key) {
            if (! filter_var($settings->get($key), FILTER_VALIDATE_BOOLEAN)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return class-string<PortalPage>
     */
    public function homePage(): string
    {
        return $this->pages()[0];
    }
}
