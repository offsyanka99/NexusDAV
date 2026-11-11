<?php

namespace App\Services\Holidays;

use Composer\InstalledVersions;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Sabre\VObject\Component\VCalendar;
use Throwable;
use Yasumi\Holiday;
use Yasumi\SubstituteHoliday;
use Yasumi\Yasumi;

/**
 * Builds the events a holiday calendar holds: one all-day VEVENT per holiday
 * and year, keyed by object URI. The same settings always give the same
 * text, so a refresh compares the stored data with these and writes only the
 * differences.
 *
 * The UID carries the calendar's id rather than the region: it stays the same
 * when the region or the language changes, so those changes update events in
 * place, and two holiday calendars of one user never share a UID.
 */
final class HolidayEvents
{
    /**
     * Raise when the events this class makes change, so every holiday
     * calendar is made again (see dataVersion()).
     */
    public const GENERATOR = 1;

    public const TYPES = [
        Holiday::TYPE_OFFICIAL,
        Holiday::TYPE_OBSERVANCE,
        Holiday::TYPE_SEASON,
        Holiday::TYPE_BANK,
        Holiday::TYPE_OTHER,
    ];

    private const KEY_LENGTH = 60;

    public function __construct(private HolidayCatalog $catalog) {}

    public static function dataVersion(): string
    {
        $yasumi = InstalledVersions::getPrettyVersion('azuyalabs/yasumi') ?? 'unknown';

        return 'yasumi '.$yasumi.'; generator '.self::GENERATOR;
    }

    /**
     * @param  list<string>  $types
     * @return array<string, string> object URI => iCalendar text
     */
    public function events(int $calendarId, string $region, string $locale, array $types, int $fromYear, int $toYear, DateTimeInterface $stamp): array
    {
        $provider = $this->catalog->provider($region);
        $fallback = $this->catalog->defaultLocale($region);
        $events = [];

        for ($year = $fromYear; $year <= $toYear; $year++) {
            foreach (Yasumi::create($provider, $year, $locale)->getHolidays() as $holiday) {
                if (! in_array($holiday->getType(), $types, true)) {
                    continue;
                }

                $uid = self::uid($calendarId, $year, $holiday->getKey());
                $events[$uid.'.ics'] = $this->event($uid, $holiday, $locale, $fallback, $stamp);
            }
        }

        ksort($events);

        return $events;
    }

    public static function uid(int $calendarId, int $year, string $key): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($key)), '-');

        if (strlen($slug) > self::KEY_LENGTH) {
            $slug = substr($slug, 0, self::KEY_LENGTH - 9).'-'.substr(md5($key), 0, 8);
        }

        return 'holiday-'.$calendarId.'-'.$year.'-'.$slug.'@nexusdav';
    }

    private function event(string $uid, Holiday $holiday, string $locale, string $fallback, DateTimeInterface $stamp): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $holiday->format('Y-m-d'), new DateTimeZone('UTC'));

        if ($date === false) {
            throw new \RuntimeException('A holiday has no valid date.');
        }

        $calendar = new VCalendar(['PRODID' => '-//NexusDAV//Holidays//EN']);
        $event = $calendar->createComponent('VEVENT', [
            'UID' => $uid,
            'DTSTAMP' => (new DateTimeImmutable('@'.$stamp->getTimestamp()))->setTimezone(new DateTimeZone('UTC')),
            'SUMMARY' => $this->name($holiday, $locale, $fallback),
            'DESCRIPTION' => $this->typeLabel($holiday->getType(), $locale),
            'TRANSP' => 'TRANSPARENT',
            'CLASS' => 'PUBLIC',
        ]);
        $event->add('DTSTART', $date, ['VALUE' => 'DATE']);
        $event->add('DTEND', $date->modify('+1 day'), ['VALUE' => 'DATE']);
        $calendar->add($event);

        return $calendar->serialize();
    }

    /**
     * The name in the chosen language, then the region's own language, then
     * English. Yasumi has no Russian or Ukrainian wording for a substitute
     * day and would fall back to English ("… observed"), so that one is
     * panel text.
     */
    private function name(Holiday $holiday, string $locale, string $fallback): string
    {
        if ($holiday instanceof SubstituteHoliday) {
            $name = $this->translated($holiday, [$locale]);

            if ($name !== null && $name !== $holiday->getKey()) {
                return $name;
            }

            return trans(':name (substitute day)', [
                'name' => $this->name($holiday->getSubstitutedHoliday(), $locale, $fallback),
            ], self::language($locale));
        }

        return $this->translated($holiday, [$locale, $fallback, 'en_US']) ?? $holiday->getKey();
    }

    /**
     * @param  list<string>  $locales
     */
    private function translated(Holiday $holiday, array $locales): ?string
    {
        try {
            return $holiday->getName($locales);
        } catch (Throwable) {
            return null;
        }
    }

    private function typeLabel(string $type, string $locale): string
    {
        $language = self::language($locale);

        return match ($type) {
            Holiday::TYPE_OFFICIAL => trans('Official holiday', [], $language),
            Holiday::TYPE_OBSERVANCE => trans('Observance', [], $language),
            Holiday::TYPE_SEASON => trans('Season', [], $language),
            Holiday::TYPE_BANK => trans('Bank holiday', [], $language),
            default => trans('Other day', [], $language),
        };
    }

    /**
     * `uk_UA` → `uk`. A language without a panel translation gets English.
     */
    private static function language(string $locale): string
    {
        return strtolower(explode('_', $locale, 2)[0]);
    }
}
