<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Models\CalendarInstance;
use App\Models\User;
use App\Services\Dav\CalendarHome;
use App\Services\Dav\CollectionService;
use App\Services\UserService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use RuntimeException;

class CalendarsRelationManager extends RelationManager
{
    protected static string $relationship = 'calendarInstances';

    protected static ?string $title = 'Calendars';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('calendar')->withCount('objects'))
            ->columns([
                TextColumn::make('uri')
                    ->label('URI'),
                TextColumn::make('displayname')
                    ->label('Name'),
                TextColumn::make('object_summary')
                    ->label('Objects')
                    ->state(function (CalendarInstance $record): string {
                        $count = (int) ($record->getAttribute('objects_count') ?? 0);
                        $components = (string) $record->calendar->components;
                        $marks = [];

                        if (str_contains($components, 'VTODO')) {
                            $marks[] = 'TASKS';
                        }

                        if (str_contains($components, 'VJOURNAL')) {
                            $marks[] = 'NOTES';
                        }

                        return $marks === [] ? (string) $count : $count.' '.implode(' ', $marks);
                    }),
                TextColumn::make('dav_path')
                    ->label('DAV path')
                    ->fontFamily(FontFamily::Mono)
                    ->state(function (CalendarInstance $record): string {
                        $owner = $this->getOwnerRecord();
                        $username = $owner instanceof User ? $owner->username : '';

                        return '/dav/calendars/'.$username.'/'.$record->uri.'/';
                    }),
            ])
            ->headerActions([
                Action::make('create')
                    ->label('Add Calendar')
                    ->icon(Heroicon::OutlinedPlus)
                    ->modalSubmitActionLabel('Save')
                    ->schema([
                        $this->uriField(false),
                        $this->displayNameField(),
                        $this->descriptionField(),
                        $this->colorField(),
                        $this->tasksField(),
                        $this->notesField(),
                    ])
                    ->action(function (array $data): void {
                        $owner = $this->owner();

                        try {
                            app(CollectionService::class)->createCalendar(
                                $owner,
                                (string) ($data['uri'] ?? ''),
                                (string) ($data['display_name'] ?? ''),
                                isset($data['description']) ? (string) $data['description'] : null,
                                (string) ($data['color'] ?? ''),
                                (bool) ($data['tasks'] ?? false),
                                (bool) ($data['notes'] ?? false),
                            );
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Could not create the calendar')
                                ->body($exception->getMessage())
                                ->send();

                            throw new Halt;
                        }

                        Notification::make()
                            ->success()
                            ->title('Calendar created')
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modalSubmitActionLabel('Save')
                    ->fillForm(function (CalendarInstance $record): array {
                        $record->loadMissing('calendar');
                        $components = (string) $record->calendar->components;

                        return [
                            'uri' => (string) $record->uri,
                            'display_name' => (string) $record->displayname,
                            'description' => (string) ($record->description ?? ''),
                            'color' => $this->formColor($record->calendarcolor),
                            'tasks' => str_contains($components, 'VTODO'),
                            'notes' => str_contains($components, 'VJOURNAL'),
                        ];
                    })
                    ->schema([
                        $this->uriField(true),
                        $this->displayNameField(),
                        $this->descriptionField(),
                        $this->colorField(),
                        $this->tasksField(),
                        $this->notesField(),
                    ])
                    ->action(function (CalendarInstance $record, array $data): void {
                        try {
                            app(CollectionService::class)->updateCalendar(
                                $this->owner(),
                                $record,
                                (string) ($data['display_name'] ?? ''),
                                isset($data['description']) ? (string) $data['description'] : null,
                                (string) ($data['color'] ?? ''),
                                (bool) ($data['tasks'] ?? false),
                                (bool) ($data['notes'] ?? false),
                            );
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Could not save the calendar')
                                ->body($exception->getMessage())
                                ->send();

                            throw new Halt;
                        }

                        Notification::make()
                            ->success()
                            ->title('Calendar saved')
                            ->send();
                    }),
                Action::make('delete')
                    ->label('Delete')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('The calendar and its events, tasks, and notes will be deleted.')
                    ->action(function (CalendarInstance $record): void {
                        app(CollectionService::class)->deleteCalendar($this->owner(), $record);

                        Notification::make()
                            ->success()
                            ->title('Calendar deleted')
                            ->send();
                    }),
            ]);
    }

    private function uriField(bool $editing): TextInput
    {
        $field = TextInput::make('uri')
            ->label('URI')
            ->maxLength(200);

        if ($editing) {
            return $field
                ->disabled()
                ->dehydrated(false);
        }

        return $field
            ->required()
            ->trim()
            ->regex(CollectionService::SLUG_PATTERN)
            ->helperText('Lowercase letters, numbers, underscores, and hyphens. Unique for this user.')
            ->default(function (): string {
                $owner = $this->getOwnerRecord();

                return $owner instanceof User
                    ? app(CollectionService::class)->suggestedCalendarUri($owner)
                    : UserService::DEFAULT_CALENDAR_URI;
            })
            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value)) {
                    $fail('The URI must be lowercase and URL-safe.');

                    return;
                }

                if (in_array($value, CalendarHome::RESERVED_NAMES, true)) {
                    $fail('This name is reserved.');

                    return;
                }

                $owner = $this->getOwnerRecord();

                if ($owner instanceof User && app(CollectionService::class)->calendarUriTaken($owner, $value)) {
                    $fail('That URI is already used.');
                }
            });
    }

    private function displayNameField(): TextInput
    {
        return TextInput::make('display_name')
            ->label('Display name')
            ->required()
            ->maxLength(255);
    }

    private function descriptionField(): Textarea
    {
        return Textarea::make('description')
            ->label('Description')
            ->rows(3)
            ->maxLength(2000);
    }

    private function colorField(): TextInput
    {
        return TextInput::make('color')
            ->label('Color')
            ->required()
            ->trim()
            ->regex(CollectionService::COLOR_PATTERN)
            ->default(fn (): string => UserService::DEFAULT_CALENDAR_COLOR)
            ->helperText('Use #RRGGBB.');
    }

    private function tasksField(): Checkbox
    {
        return $this->componentField('tasks', 'Tasks (VTODO)', 'VTODO');
    }

    private function notesField(): Checkbox
    {
        return $this->componentField('notes', 'Notes (VJOURNAL)', 'VJOURNAL');
    }

    private function componentField(string $name, string $label, string $component): Checkbox
    {
        return Checkbox::make($name)
            ->label($label)
            ->default(fn (): bool => $this->componentAllowed($component))
            ->disabled(fn (): bool => ! $this->componentAllowed($component))
            ->dehydrated(fn (): bool => $this->componentAllowed($component));
    }

    private function componentAllowed(string $component): bool
    {
        return in_array($component, app(CollectionService::class)->allowedComponents(), true);
    }

    /**
     * DAVx5 and Apple store #RRGGBBAA, and a client may store nothing. The form
     * accepts #RRGGBB only, so start from the RGB part or the default color.
     */
    private function formColor(mixed $stored): string
    {
        $stored = is_string($stored) ? trim($stored) : '';

        if (preg_match('/^#[0-9A-Fa-f]{6}(?:[0-9A-Fa-f]{2})?$/', $stored) === 1) {
            return strtoupper(substr($stored, 0, 7));
        }

        return UserService::DEFAULT_CALENDAR_COLOR;
    }

    private function owner(): User
    {
        $owner = $this->getOwnerRecord();

        if (! $owner instanceof User) {
            throw new RuntimeException('Calendars belong to a user.');
        }

        return $owner;
    }
}
