<?php

namespace App\Services\Portal;

use Carbon\CarbonImmutable;

/**
 * One occurrence of an event in a time range, in the user's time zone: a
 * single event, or one instance of a series (recurrenceId is set). An
 * all-day occurrence starts at midnight and ends at the midnight after its
 * last day.
 */
final class Occurrence
{
    public function __construct(
        public readonly CollectionRef $calendar,
        public readonly string $uri,
        public readonly string $uid,
        public readonly ?string $title,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly bool $allDay,
        public readonly bool $recurring,
        public readonly ?string $recurrenceId,
        public readonly bool $readOnly,
    ) {}
}
