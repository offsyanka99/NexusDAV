<?php

namespace App\Filament\Resources\Users\Pages;

use App\Exceptions\LastActiveAdminException;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\UserService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

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
                ->title('Cannot change the last active admin')
                ->body($exception->getMessage())
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
