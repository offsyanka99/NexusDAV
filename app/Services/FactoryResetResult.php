<?php

namespace App\Services;

/**
 * What a factory reset deleted.
 */
final readonly class FactoryResetResult
{
    public function __construct(
        public int $users,
        public int $calendars,
        public int $addressBooks,
        public int $items,
        public int $fileBytes,
    ) {}
}
