<?php

namespace App\Services\Portal;

/**
 * The occurrences of a range, sorted by start. truncated is set when some
 * were left out: more than CalendarReader::MAX_OCCURRENCES, or a series that
 * passed CalendarReader::RECURRENCE_LIMIT (that series contributes none).
 */
final class OccurrenceList
{
    /**
     * @param  list<Occurrence>  $occurrences
     */
    public function __construct(
        public readonly array $occurrences,
        public readonly bool $truncated,
    ) {}
}
