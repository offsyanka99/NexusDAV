<?php

namespace App\Portal\Apps;

use App\Filament\Portal\Calendar;
use App\Portal\PortalApp;
use Filament\Support\Icons\Heroicon;

final class CalendarApp extends PortalApp
{
    public function id(): string
    {
        return 'calendar';
    }

    public function label(): string
    {
        return __('Calendar');
    }

    public function icon(): Heroicon
    {
        return Heroicon::OutlinedCalendarDays;
    }

    public function sort(): int
    {
        return 10;
    }

    public function switches(): array
    {
        return ['caldav'];
    }

    public function pages(): array
    {
        return [Calendar::class];
    }
}
