<?php

namespace App\Services\Holidays;

/**
 * What a refresh wrote to a holiday calendar.
 */
final readonly class HolidayChanges
{
    public function __construct(
        public int $added,
        public int $changed,
        public int $removed,
    ) {}

    public function none(): bool
    {
        return $this->added === 0 && $this->changed === 0 && $this->removed === 0;
    }
}
