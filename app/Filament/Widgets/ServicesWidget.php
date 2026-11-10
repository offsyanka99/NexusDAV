<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Settings;
use App\Services\SettingsService;
use App\Support\WorkerStatusLabel;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

/**
 * The service switches, On or Off, with a lock when the environment
 * decides. Changes are made on the Settings page.
 */
class ServicesWidget extends SchemaWidget
{
    protected static ?int $sort = 2;

    protected function heading(): string
    {
        return __('Services');
    }

    protected function section(Section $section): Section
    {
        return $section
            ->columns(['default' => 2, 'md' => 4, 'xl' => 8])
            ->footer([
                Action::make('manageSettings')
                    ->label(__('Manage in Settings'))
                    ->icon(Heroicon::ArrowRight)
                    ->iconPosition('after')
                    ->link()
                    ->url(Settings::getUrl()),
            ]);
    }

    protected function entries(): array
    {
        $settings = app(SettingsService::class);
        $entries = [];

        foreach ([
            'caldav' => __('CalDAV'),
            'carddav' => __('CardDAV'),
            'tasks' => __('Tasks'),
            'notes' => __('Notes'),
            'sharing' => __('Sharing'),
            'files' => __('Files'),
            'push' => __('WebDAV-Push'),
        ] as $key => $label) {
            $on = (bool) $settings->get($key);
            $entry = TextEntry::make($key)
                ->label($label)
                ->state($on ? __('On') : __('Off'))
                ->badge()
                ->color($on ? 'success' : 'gray')
                ->icon($on ? Heroicon::CheckCircle : Heroicon::MinusCircle);

            if ($settings->isLocked($key)) {
                $entry->hintIcon(Heroicon::LockClosed, tooltip: __('Set by environment'));
            }

            $entries[] = $entry;
        }

        // Not a switch: whether the worker that sends push messages runs.
        $worker = WorkerStatusLabel::describe();
        $entries[] = TextEntry::make('worker')
            ->label(__('Queue worker'))
            ->state($worker['label'])
            ->badge()
            ->color($worker['color'])
            ->icon($worker['icon'])
            ->tooltip($worker['detail']);

        return $entries;
    }
}
