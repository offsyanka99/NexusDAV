<?php

namespace App\Services\Dav;

use DateTimeImmutable;
use DateTimeZone;
use Sabre\VObject\Component\VCalendar;

/**
 * The calendar-timezone value for a new calendar: a VCALENDAR holding one
 * VTIMEZONE for an IANA zone. Clients read the TZID; the transitions from
 * last year to 2037 cover the dates a client resolves floating times for.
 */
final class CalendarTimezone
{
    public static function vcalendar(string $tzid): string
    {
        $zone = new DateTimeZone($tzid);
        $calendar = new VCalendar(['PRODID' => '-//NexusDAV//EN']);
        $component = $calendar->createComponent('VTIMEZONE', ['TZID' => $tzid], false);
        $calendar->add($component);

        $start = (new DateTimeImmutable('first day of january last year', $zone))->getTimestamp();
        $end = (new DateTimeImmutable('2037-12-31 23:59:59', $zone))->getTimestamp();
        $transitions = $zone->getTransitions($start, $end);

        if ($transitions === []) {
            $offset = $zone->getOffset(new DateTimeImmutable('now', $zone));
            $component->add($calendar->createComponent('STANDARD', [
                'DTSTART' => '19700101T000000',
                'TZOFFSETFROM' => self::offset($offset),
                'TZOFFSETTO' => self::offset($offset),
            ], false));

            return $calendar->serialize();
        }

        // The first entry is the state at $start, not a change.
        $previous = (int) $transitions[0]['offset'];

        if (count($transitions) === 1) {
            $component->add($calendar->createComponent('STANDARD', [
                'DTSTART' => '19700101T000000',
                'TZOFFSETFROM' => self::offset($previous),
                'TZOFFSETTO' => self::offset($previous),
                'TZNAME' => (string) $transitions[0]['abbr'],
            ], false));

            return $calendar->serialize();
        }

        foreach (array_slice($transitions, 1) as $transition) {
            $offset = (int) $transition['offset'];
            // DTSTART is local time under the offset in effect before the change.
            $local = gmdate('Ymd\THis', (int) $transition['ts'] + $previous);

            $component->add($calendar->createComponent($transition['isdst'] ? 'DAYLIGHT' : 'STANDARD', [
                'DTSTART' => $local,
                'TZOFFSETFROM' => self::offset($previous),
                'TZOFFSETTO' => self::offset($offset),
                'TZNAME' => (string) $transition['abbr'],
            ], false));

            $previous = $offset;
        }

        return $calendar->serialize();
    }

    private static function offset(int $seconds): string
    {
        $sign = $seconds < 0 ? '-' : '+';
        $seconds = abs($seconds);

        return sprintf('%s%02d%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}
