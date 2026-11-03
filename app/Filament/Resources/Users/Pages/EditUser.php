<?php

namespace App\Filament\Resources\Users\Pages;

use App\Exceptions\LastActiveAdminException;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\UserService;
use App\Support\PanelErrorTranslator;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Alignment;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    public function getTitle(): string
    {
        return __('Edit User');
    }

    public function getFormActionsAlignment(): Alignment
    {
        return Alignment::End;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['password']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof User) {
            throw new InvalidArgumentException('Expected a user.');
        }

        try {
            return app(UserService::class)->update($record, $data);
        } catch (LastActiveAdminException $exception) {
            Notification::make()
                ->danger()
                ->title(__('Cannot change the last active admin'))
                ->body(PanelErrorTranslator::translate($exception->getMessage()))
                ->send();

            throw new Halt;
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->danger()
                ->title(__('Could not save the user'))
                ->body(PanelErrorTranslator::translate($exception->getMessage()))
                ->send();

            throw new Halt;
        }
    }

    /**
     * @return array<Action | ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            UserResource::deleteAction(),
        ];
    }
}
