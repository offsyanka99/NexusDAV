<?php

namespace App\Services\Portal\Exceptions;

/**
 * A create whose name is taken, or an item whose UID another item of the
 * collection already has.
 */
final class AlreadyExists extends PortalException
{
    public function __construct(public readonly bool $forFile = false, string $message = 'It already exists.')
    {
        parent::__construct($message);
    }

    public static function uid(): self
    {
        return new self(false, 'Another item in this collection has the same UID.');
    }

    public function title(): string
    {
        return $this->forFile
            ? __('A file or folder with this name already exists.')
            : __('This item already exists in this collection.');
    }
}
