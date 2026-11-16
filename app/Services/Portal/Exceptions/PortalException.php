<?php

namespace App\Services\Portal\Exceptions;

use RuntimeException;

/**
 * A refused portal write or read. PortalErrors turns it into a notification
 * with title() and body(), in the user's language. The message stays
 * English for logs and tests.
 */
abstract class PortalException extends RuntimeException
{
    abstract public function title(): string;

    public function body(): ?string
    {
        return null;
    }
}
