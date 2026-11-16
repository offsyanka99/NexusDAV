<?php

namespace App\Services\Portal;

use App\Models\User;
use App\Portal\PortalTime;
use App\Services\Dav\CalendarBackend;
use Carbon\CarbonImmutable;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\Property;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;
use Sabre\VObject\Reader;
use Sabre\VObject\Recur\MaxInstancesExceededException;
use Sabre\VObject\Settings;
use Throwable;

/**
 * The events of the user's visible calendars in a time range, recurring
 * events expanded, in the user's time zone (Decision 9: the grid reads the
 * DAV data, not the index). Candidates come from sabre's calendar-query on
 * the calendar's first and last occurrence; vobject's expand() then gives
 * the exact occurrences, with overrides and exceptions applied, all-day
 * dates and floating times read in the user's zone.
 */
final class CalendarReader
{
    /**
     * At most this many occurrences for one range, so an open-ended rule
     * cannot flood a request.
     */
    public const MAX_OCCURRENCES = 2000;

    /**
     * The longest range a view may ask for. A month grid is about six weeks;
     * a year is the ceiling (366 days covers a leap year).
     */
    public const MAX_RANGE_DAYS = 366;

    /**
     * vobject's own ceiling is 3,500, and EventIterator counts from the
     * series' DTSTART, not from the start of the range. A daily event kept
     * for ten years, or an hourly one kept for a few months, would otherwise
     * disappear from every later range. Raised around the candidate query
     * and around expand(), then restored: the static is process-wide and
     * CalDAV reads it too. The query's time-range check walks the same
     * counter and throws before expand() is reached.
     */
    public const RECURRENCE_LIMIT = 100_000;

    private const CHUNK = 100;

    public function __construct(private PortalCollections $collections) {}

    /**
     * @param  list<int>|null  $calendarIds  only these of the user's calendars; null for all
     */
    public function between(User $user, DateTimeInterface $start, DateTimeInterface $end, ?array $calendarIds = null): OccurrenceList
    {
        $zone = new DateTimeZone(PortalTime::timezone($user));
        // In the user's zone: expand() reads all-day dates and floating times
        // of single events in the zone of the range it is given.
        $from = DateTimeImmutable::createFromInterface($start)->setTimezone($zone);
        $to = DateTimeImmutable::createFromInterface($end)->setTimezone($zone);

        if ($to <= $from) {
            return new OccurrenceList([], false);
        }

        if ($from->add(new DateInterval('P'.self::MAX_RANGE_DAYS.'D')) < $to) {
            throw new InvalidArgumentException('A calendar range cannot be longer than a year.');
        }

        $occurrences = [];
        $truncated = false;
        // Once the earliest MAX_OCCURRENCES are known, later objects are
        // expanded only up to that last start. expand() is exclusive of its end.
        $expandTo = $to;

        foreach ($this->collections->calendars($user) as $calendar) {
            if ($calendarIds !== null && ! in_array($calendar->id, $calendarIds, true)) {
                continue;
            }

            foreach ($this->objects($calendar, $from, $to, $truncated) as $object) {
                foreach ($this->expand($calendar, $object['uri'], $object['data'], $from, $expandTo, $zone, $truncated) as $occurrence) {
                    // Only what overlaps the range: a zero-length one at its start counts.
                    if ($occurrence->start < $to && ($occurrence->end > $from || $occurrence->start >= $from)) {
                        $occurrences[] = $occurrence;
                    }
                }

                if (count($occurrences) > self::MAX_OCCURRENCES) {
                    $this->sort($occurrences);
                    $occurrences = array_slice($occurrences, 0, self::MAX_OCCURRENCES);
                    $truncated = true;
                    $expandTo = $this->earliestLimit($occurrences, $to);
                }
            }
        }

        $this->sort($occurrences);

        if (count($occurrences) > self::MAX_OCCURRENCES) {
            $occurrences = array_slice($occurrences, 0, self::MAX_OCCURRENCES);
            $truncated = true;
        }

        return new OccurrenceList($occurrences, $truncated);
    }

    /**
     * @return iterable<int, array{uri: string, data: string}>
     */
    private function objects(CollectionRef $calendar, DateTimeImmutable $from, DateTimeImmutable $to, bool &$truncated): iterable
    {
        $backend = app(CalendarBackend::class);
        // sabre keeps a floating or all-day event's first and last occurrence
        // as if it were UTC: a day on each side finds it from any zone.
        $from = $from->modify('-1 day');
        $to = $to->modify('+1 day');
        $filters = [
            'name' => 'VCALENDAR',
            'comp-filters' => [[
                'name' => 'VEVENT',
                'comp-filters' => [],
                'prop-filters' => [],
                'is-not-defined' => false,
                'time-range' => ['start' => $from, 'end' => $to],
            ]],
            'prop-filters' => [],
            'is-not-defined' => false,
            'time-range' => null,
        ];

        try {
            $uris = $this->recurrences(fn (): array => $backend->calendarQuery($calendar->backendId(), $filters));
        } catch (MaxInstancesExceededException) {
            // The check stops the whole query once one series passes the
            // limit, before any URI is returned. The stored first and last
            // occurrence still list the candidates; expand() reports that series.
            $truncated = true;
            $uris = $this->urisBetween($calendar, $from, $to);
        }

        foreach (array_chunk($uris, self::CHUNK) as $chunk) {
            foreach ($backend->getMultipleCalendarObjects($calendar->backendId(), $chunk) as $row) {
                yield ['uri' => (string) $row['uri'], 'data' => (string) ($row['calendardata'] ?? '')];
            }
        }
    }

    /**
     * The same bounds as sabre's calendar query, without the time-range walk.
     *
     * @return list<string>
     */
    private function urisBetween(CollectionRef $calendar, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return DB::table('calendarobjects')
            ->where('calendarid', $calendar->id)
            ->where('componenttype', 'VEVENT')
            ->where('lastoccurence', '>', $from->getTimestamp())
            ->where('firstoccurence', '<', $to->getTimestamp())
            ->pluck('uri')
            ->all();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    private function recurrences(callable $read): mixed
    {
        $previous = Settings::$maxRecurrences;

        if ($previous !== -1 && $previous < self::RECURRENCE_LIMIT) {
            Settings::$maxRecurrences = self::RECURRENCE_LIMIT;
        }

        try {
            return $read();
        } finally {
            Settings::$maxRecurrences = $previous;
        }
    }

    /**
     * @return list<Occurrence>
     */
    private function expand(CollectionRef $calendar, string $uri, string $data, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeZone $zone, bool &$truncated): array
    {
        try {
            $object = Reader::read($data, Reader::OPTION_FORGIVING);
        } catch (Throwable) {
            return [];
        }

        if (! $object instanceof VCalendar) {
            $object->destroy();

            return [];
        }

        try {
            return $this->recurrences(function () use ($calendar, $uri, $object, $from, $to, $zone, &$truncated): array {
                try {
                    $recurring = $this->isSeries($object);
                    $expanded = $object->expand($from, $to, $zone);
                } catch (MaxInstancesExceededException) {
                    // expand() throws with no partial result, and for an old series
                    // it throws while still stepping from DTSTART toward the range.
                    $truncated = true;
                    Log::info('An event has more instances than the reader expands', ['calendar' => $calendar->id]);

                    return [];
                } catch (Throwable $exception) {
                    // A rule with no instance left, or data vobject cannot expand.
                    Log::info('An event could not be expanded', ['calendar' => $calendar->id, 'error' => $exception::class]);

                    return [];
                }

                $occurrences = [];

                foreach ($expanded->select('VEVENT') as $event) {
                    try {
                        $occurrence = $event instanceof Component ? $this->occurrence($calendar, $uri, $event, $recurring, $zone) : null;
                    } catch (Throwable) {
                        // A value vobject cannot read (a broken DURATION in an override).
                        $occurrence = null;
                    }

                    if ($occurrence !== null) {
                        $occurrences[] = $occurrence;
                    }
                }

                $expanded->destroy();

                return $occurrences;
            });
        } finally {
            $object->destroy();
        }
    }

    /**
     * The exclusive end for the next object: far enough to see an occurrence
     * that starts with the last one kept (ties break on end, then URI), and
     * no further than the range.
     *
     * @param  list<Occurrence>  $occurrences  the earliest MAX_OCCURRENCES, sorted
     */
    private function earliestLimit(array $occurrences, DateTimeImmutable $rangeEnd): DateTimeImmutable
    {
        $until = DateTimeImmutable::createFromInterface($occurrences[array_key_last($occurrences)]->start)->modify('+1 second');

        return $until < $rangeEnd ? $until : $rangeEnd;
    }

    /**
     * @param  list<Occurrence>  $occurrences
     */
    private function sort(array &$occurrences): void
    {
        usort($occurrences, static fn (Occurrence $a, Occurrence $b): int => [$a->start, $a->end, $a->uri] <=> [$b->start, $b->end, $b->uri]);
    }

    private function occurrence(CollectionRef $calendar, string $uri, Component $event, bool $recurring, DateTimeZone $zone): ?Occurrence
    {
        $start = $this->first($event, 'DTSTART');

        if (! $start instanceof DateTimeProperty) {
            return null;
        }

        $allDay = ! $start->hasTime();
        $begin = $this->local($start, $zone);
        $endProperty = $this->first($event, 'DTEND');
        $duration = $this->first($event, 'DURATION');

        if ($endProperty instanceof DateTimeProperty) {
            $finish = $this->local($endProperty, $zone);
        } elseif ($duration !== null) {
            $finish = $begin->add(DateTimeParser::parseDuration((string) $duration));
        } else {
            $finish = $allDay ? $begin->addDay() : $begin;
        }

        $recurrence = $this->first($event, 'RECURRENCE-ID');

        return new Occurrence(
            calendar: $calendar,
            uri: $uri,
            uid: (string) $this->first($event, 'UID'),
            title: ($summary = trim((string) $this->first($event, 'SUMMARY'))) === '' ? null : $summary,
            start: $begin,
            end: $finish < $begin ? $begin : $finish,
            allDay: $allDay,
            recurring: $recurring,
            recurrenceId: $recurring && $recurrence !== null ? (string) $recurrence->getValue() : null,
            readOnly: ! $calendar->isWritable(),
        );
    }

    /**
     * expand() gives UTC times, and keeps all-day dates as dates: a date is
     * midnight in the user's zone.
     */
    private function local(DateTimeProperty $property, DateTimeZone $zone): CarbonImmutable
    {
        if (! $property->hasTime()) {
            return CarbonImmutable::parse($property->getDateTime($zone)->format('Y-m-d'), $zone);
        }

        return CarbonImmutable::instance($property->getDateTime($zone))->setTimezone($zone);
    }

    private function isSeries(VCalendar $calendar): bool
    {
        foreach ($calendar->select('VEVENT') as $event) {
            if ($event instanceof Component && ($this->first($event, 'RRULE') !== null || $this->first($event, 'RDATE') !== null || $this->first($event, 'RECURRENCE-ID') !== null)) {
                return true;
            }
        }

        return false;
    }

    private function first(Component $component, string $name): ?Property
    {
        foreach ($component->select($name) as $property) {
            if ($property instanceof Property) {
                return $property;
            }
        }

        return null;
    }
}
