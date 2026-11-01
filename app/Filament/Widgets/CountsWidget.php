<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Diagnostics;
use App\Filament\Resources\Users\UserResource;
use App\Services\ServerCounts;
use App\Services\SettingsService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Throwable;

/**
 * How much is on the server. A card for a service that is off is hidden.
 * Numbers are cached for a minute; there is no polling.
 */
class CountsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected function getColumns(): int
    {
        return 4;
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        try {
            return $this->stats();
        } catch (Throwable $exception) {
            Log::warning('A Dashboard card could not load', ['widget' => static::class, 'exception' => $exception]);

            return [Stat::make(__('Counts'), __('Unavailable'))->color('danger')];
        }
    }

    /**
     * @return array<Stat>
     */
    private function stats(): array
    {
        $settings = app(SettingsService::class);
        $counts = app(ServerCounts::class)->all();
        $caldav = (bool) $settings->get('caldav');
        $carddav = (bool) $settings->get('carddav');
        $tasks = (bool) $settings->get('tasks');
        $notes = (bool) $settings->get('notes');
        $users = UserResource::getUrl('index');

        $stats = [
            Stat::make(__('Users'), Number::format($counts['users']))
                ->description($counts['disabled_users'] > 0 ? __(':count disabled', ['count' => Number::format($counts['disabled_users'])]) : null)
                ->url($users),
        ];

        if ($caldav) {
            $stats[] = Stat::make(__('Calendars'), Number::format($counts['calendars']))->url($users);
        }

        if ($carddav) {
            $stats[] = Stat::make(__('Address books'), Number::format($counts['address_books']))->url($users);
        }

        $stats[] = Stat::make(__('App passwords'), Number::format($counts['app_passwords']))
            ->description(__('active'))
            ->url(Diagnostics::getUrl());

        if ($caldav) {
            $stats[] = $tasks
                ? Stat::make(__('Events and tasks'), Number::format($counts['events'] + $counts['tasks']))
                : Stat::make(__('Events'), Number::format($counts['events']));
        }

        if ($caldav && $notes) {
            $stats[] = Stat::make(__('Notes'), Number::format($counts['notes']));
        }

        if ($carddav) {
            $stats[] = Stat::make(__('Contacts'), Number::format($counts['contacts']));
        }

        return $stats;
    }
}
