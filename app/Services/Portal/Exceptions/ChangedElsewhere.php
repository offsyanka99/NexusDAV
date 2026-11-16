<?php

namespace App\Services\Portal\Exceptions;

/**
 * The item's ETag is not the one the form was opened with: another device
 * changed or deleted it in between. Nothing is overwritten.
 */
final class ChangedElsewhere extends PortalException
{
    public function __construct()
    {
        parent::__construct('The item was changed elsewhere.');
    }

    public function title(): string
    {
        return __('This item was changed on another device. Reload to see the new version.');
    }
}
