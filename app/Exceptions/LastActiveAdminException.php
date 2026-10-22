<?php

namespace App\Exceptions;

use RuntimeException;

final class LastActiveAdminException extends RuntimeException
{
    public const CANNOT_DELETE = 'The last active admin cannot be deleted. Create another active admin first.';

    public const CANNOT_CHANGE = 'The last active admin cannot be disabled or demoted. Create another active admin first.';

    public static function cannotDelete(): self
    {
        return new self(self::CANNOT_DELETE);
    }

    public static function cannotChange(): self
    {
        return new self(self::CANNOT_CHANGE);
    }
}
