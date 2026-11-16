<?php

namespace App\Services\Portal;

use App\Services\Dav\BusyDatabase;
use App\Services\Portal\Exceptions\PortalException;
use Filament\Notifications\Notification;
use Throwable;

/**
 * The portal's refusals as notifications in the user's language. A page
 * catches what a store or reader throws and hands it here; anything else is
 * a bug and is thrown again.
 */
final class PortalErrors
{
    public static function handles(Throwable $exception): bool
    {
        return $exception instanceof PortalException || BusyDatabase::caused($exception);
    }

    public static function notification(Throwable $exception): Notification
    {
        if ($exception instanceof PortalException) {
            return Notification::make()
                ->danger()
                ->title($exception->title())
                ->body($exception->body());
        }

        if (BusyDatabase::caused($exception)) {
            return Notification::make()
                ->danger()
                ->title(__('The server is busy. Try again in a moment.'));
        }

        throw $exception;
    }

    /**
     * Sends the notification, or throws the exception again when it is not a
     * refusal the user can act on.
     */
    public static function send(Throwable $exception): void
    {
        if (! self::handles($exception)) {
            throw $exception;
        }

        self::notification($exception)->send();
    }
}
