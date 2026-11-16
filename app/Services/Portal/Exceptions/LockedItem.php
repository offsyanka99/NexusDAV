<?php

namespace App\Services\Portal\Exceptions;

/**
 * A file or folder a WebDAV client holds a lock on.
 */
final class LockedItem extends PortalException
{
    public function __construct()
    {
        parent::__construct('The file is locked.');
    }

    public function title(): string
    {
        return __('Another app is editing this file.');
    }

    public function body(): string
    {
        return __('Try again when it is done.');
    }
}
