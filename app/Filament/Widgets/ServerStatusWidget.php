<?php

namespace App\Filament\Widgets;

use App\Support\DatabaseHealth;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

/**
 * Application, database and version. The database check is the one behind
 * GET /health, and a failure shows only "Unavailable".
 */
class ServerStatusWidget extends SchemaWidget
{
    protected static ?int $sort = 1;

    protected function heading(): string
    {
        return __('Server status');
    }

    protected function section(Section $section): Section
    {
        return $section->columns(3);
    }

    protected function entries(): array
    {
        $database = app(DatabaseHealth::class);
        $up = $database->isUp();

        return [
            TextEntry::make('application')
                ->label(__('Application'))
                ->state(__('OK'))
                ->badge()
                ->color('success')
                ->icon(Heroicon::CheckCircle),
            TextEntry::make('database')
                ->label(__('Database'))
                ->state($up ? __('OK').' ('.$database->label().')' : __('Unavailable'))
                ->badge()
                ->color($up ? 'success' : 'danger')
                ->icon($up ? Heroicon::CheckCircle : Heroicon::XCircle),
            TextEntry::make('version')
                ->label(__('Version'))
                ->state('NexusDAV '.config('nexusdav.version', 'dev')),
        ];
    }
}
