<?php

namespace App\Portal\Apps;

use App\Filament\Portal\Tasks;
use App\Portal\PortalApp;
use Filament\Support\Icons\Heroicon;

final class TasksApp extends PortalApp
{
    public function id(): string
    {
        return 'tasks';
    }

    public function label(): string
    {
        return __('Tasks');
    }

    public function icon(): Heroicon
    {
        return Heroicon::OutlinedCheckCircle;
    }

    public function sort(): int
    {
        return 20;
    }

    public function switches(): array
    {
        return ['caldav', 'tasks'];
    }

    public function pages(): array
    {
        return [Tasks::class];
    }
}
