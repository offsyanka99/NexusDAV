<?php

namespace App\Filament\Pages;

use App\Services\SettingsService;
use App\Support\PanelErrorTranslator;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

/**
 * A form of panel settings. A setting whose environment variable is set is
 * shown read-only with a lock; saving never writes it.
 *
 * @property-read Schema $form
 */
abstract class SettingsPage extends Page
{
    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * @return list<string>
     */
    abstract protected function settingKeys(): array;

    public function mount(): void
    {
        $this->fillForm();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label(__('Save'))
                            ->submit('save')
                            ->keyBindings(['mod+s']),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        $settings = app(SettingsService::class);
        $state = $this->form->getState();
        $values = [];

        foreach ($this->settingKeys() as $key) {
            if (array_key_exists($key, $state) && ! $settings->isLocked($key)) {
                $values[$key] = $state[$key];
            }
        }

        try {
            $settings->save($values);
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->danger()
                ->title(__('Could not save'))
                ->body(PanelErrorTranslator::translate($exception->getMessage()))
                ->send();

            return;
        }

        $this->fillForm();

        Notification::make()
            ->success()
            ->title(__('Saved'))
            ->body(__('The change applies from the next request. No restart is needed.'))
            ->send();
    }

    protected function fillForm(): void
    {
        $settings = app(SettingsService::class);
        $state = [];

        foreach ($this->settingKeys() as $key) {
            $value = $settings->get($key);
            $state[$key] = is_int($value) && $this->usesStringOptions($key) ? (string) $value : $value;
        }

        $this->form->fill($state);
    }

    /**
     * Select fields compare option keys as strings.
     */
    protected function usesStringOptions(string $key): bool
    {
        return false;
    }

    /**
     * Read-only with a lock when the environment decides the value.
     *
     * @template TField of Field
     *
     * @param  TField  $field
     * @return TField
     */
    protected function lockable(Field $field, string $key): Field
    {
        $settings = app(SettingsService::class);

        if (! $settings->isLocked($key)) {
            return $field;
        }

        $field
            ->disabled()
            ->dehydrated(false)
            ->hint(__('Set by :name', ['name' => $settings->envName($key)]))
            ->hintIcon(Heroicon::LockClosed, tooltip: __('Set by environment'));

        return $field;
    }
}
