<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Services\UserService;
use App\Support\PanelErrorTranslator;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()
            ->label(__('Create & Add Another'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(UserService::class)->create($data);
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->danger()
                ->title(__('Could not create the user'))
                ->body(PanelErrorTranslator::translate($exception->getMessage()))
                ->send();

            throw new Halt;
        }
    }
}
