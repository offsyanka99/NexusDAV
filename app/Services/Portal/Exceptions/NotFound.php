<?php

namespace App\Services\Portal\Exceptions;

/**
 * The collection, item, file or folder does not exist, or is not the user's
 * to see. Both answer the same, so a probing request learns nothing
 * (Decision 10).
 */
final class NotFound extends PortalException
{
    public function __construct()
    {
        parent::__construct('Not found.');
    }

    public function title(): string
    {
        return __('This item no longer exists, or you cannot open it.');
    }
}
