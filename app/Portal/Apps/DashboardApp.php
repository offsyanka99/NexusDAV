<?php

namespace App\Portal\Apps;

use App\Filament\Portal\Dashboard;
use App\Portal\PortalApp;
use Filament\Support\Icons\Heroicon;

final class DashboardApp extends PortalApp
{
    public function id(): string
    {
        return 'dashboard';
    }

    public function label(): string
    {
        return __('Dashboard');
    }

    public function icon(): Heroicon
    {
        return Heroicon::OutlinedHome;
    }

    public function sort(): int
    {
        return 0;
    }

    public function pages(): array
    {
        return [Dashboard::class];
    }
}
