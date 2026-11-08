<?php

namespace App\Filament\Pages;

use App\Services\SettingsService;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * DAV services and the log level. Configuration holds server-wide preferences.
 */
class Settings extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'settings';

    public static function getNavigationLabel(): string
    {
        return __('Settings');
    }

    public function getTitle(): string
    {
        return __('Settings');
    }

    /**
     * @return list<string>
     */
    protected function settingKeys(): array
    {
        return ['caldav', 'carddav', 'tasks', 'notes', 'sharing', 'files', 'files_max_upload_mb', 'log_level', 'sync_history_days'];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Services'))
                    ->description(__('A service that is off answers 403 on DAV. Its data is kept and comes back when the service is turned on again.'))
                    ->schema([
                        $this->lockable(
                            Toggle::make('caldav')
                                ->label(__('CalDAV'))
                                ->helperText(__('Calendars, and the tasks and notes stored in them. Paths under /dav/calendars/.')),
                            'caldav',
                        ),
                        $this->lockable(
                            Toggle::make('carddav')
                                ->label(__('CardDAV'))
                                ->helperText(__('Address books. Paths under /dav/addressbooks/.')),
                            'carddav',
                        ),
                        $this->lockable(
                            Toggle::make('tasks')
                                ->label(__('Tasks (VTODO)'))
                                ->helperText(__('When off, calendars stop offering tasks: no new task can be created or changed. Existing tasks stay readable and can be deleted.')),
                            'tasks',
                        ),
                        $this->lockable(
                            Toggle::make('notes')
                                ->label(__('Notes (VJOURNAL)'))
                                ->helperText(__('When off, calendars stop offering notes: no new note can be created or changed. Existing notes stay readable and can be deleted.')),
                            'notes',
                        ),
                        $this->lockable(
                            Toggle::make('sharing')
                                ->label(__('Sharing'))
                                ->helperText(__("When off, calendars and address books shared with a user are not listed for them, and their paths answer 404. The shares are kept and come back when sharing is turned on. The owner's own calendars and address books do not change.")),
                            'sharing',
                        ),
                    ]),
                Section::make(__('Files'))
                    ->description(__("Each user's own WebDAV storage at /dav/files/<username>/. App passwords reach it too."))
                    ->schema([
                        $this->lockable(
                            Toggle::make('files')
                                ->label(__('Files'))
                                ->live()
                                ->helperText(__('When off, paths under /dav/files/ answer 403 and the files are kept on the data volume.')),
                            'files',
                        ),
                        $this->lockable(
                            $this->number('files_max_upload_mb', __('Maximum upload size'))
                                ->suffix(__('MB'))
                                ->visible(fn (Get $get): bool => (bool) $get('files'))
                                ->helperText(__('A larger upload is refused with 413 and leaves nothing behind. Above 128 MB, raise the request body limit of the reverse proxy too.')),
                            'files_max_upload_mb',
                        ),
                    ]),
                Section::make(__('Logging'))
                    ->schema([
                        $this->lockable(
                            Select::make('log_level')
                                ->label(__('Log level'))
                                ->options(array_combine(SettingsService::LOG_LEVELS, array_map('ucfirst', SettingsService::LOG_LEVELS)))
                                ->required()
                                ->selectablePlaceholder(false)
                                ->helperText(__('The least severe message that is written to the container log. Use Info or higher in production.')),
                            'log_level',
                        ),
                    ]),
                Section::make(__('Sync'))
                    ->schema([
                        $this->lockable(
                            $this->number('sync_history_days', __('Keep sync history for'))
                                ->suffix(__('days'))
                                ->helperText(__('Changes older than this are dropped. A client that was offline longer has to sync that collection from the start.')),
                            'sync_history_days',
                        ),
                    ]),
            ])
            ->statePath('data');
    }

    private function number(string $key, string $label): TextInput
    {
        $definition = SettingsService::DEFINITIONS[$key];

        return TextInput::make($key)
            ->label($label)
            ->integer()
            ->required()
            ->minValue($definition['min'] ?? null)
            ->maxValue($definition['max'] ?? null);
    }
}
