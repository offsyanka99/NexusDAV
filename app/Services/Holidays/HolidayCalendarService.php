<?php

namespace App\Services\Holidays;

use App\Models\CalendarInstance;
use App\Models\HolidayCalendar;
use App\Models\User;
use App\Services\Dav\BinaryValue;
use App\Services\Dav\CalendarBackend;
use App\Services\Dav\CollectionService;
use App\Services\Dav\DavWriteLock;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Creates holiday calendars and keeps their events up to date (PR 41 of the
 * Version 1.x.x plan).
 *
 * A holiday calendar is a normal calendar of its owner, with events only,
 * that clients can read but not change (CalendarNode). Its events are written
 * here, through the calendar backend, so every change moves the sync token
 * and reaches WebDAV-Push like a client's write. A refresh writes only what
 * differs from the stored events. Each call runs in one transaction under the
 * calendar's write lock: two refreshes of one calendar wait for each other,
 * and a failure leaves the calendar as it was.
 */
final class HolidayCalendarService
{
    public const YEARS_BACK = 1;

    public const YEARS_AHEAD = 3;

    public const DEFAULT_TYPES = ['official'];

    public function __construct(
        private HolidayCatalog $catalog,
        private HolidayEvents $events,
        private CollectionService $collections,
    ) {}

    /**
     * @param  list<string>|null  $types
     */
    public function create(User $user, string $uri, string $displayName, string $color, string $region, ?string $locale = null, ?array $types = null): HolidayCalendar
    {
        $locale ??= $this->catalog->defaultLocale($region);
        $types = $this->types($types ?? self::DEFAULT_TYPES);
        $this->assertRegion($region);
        $this->assertLocale($locale);

        return DB::transaction(function () use ($user, $uri, $displayName, $color, $region, $locale, $types): HolidayCalendar {
            $this->collections->createCalendar($user, $uri, $displayName, null, $color, false, false);

            $instance = CalendarInstance::query()
                ->where('principaluri', $user->principal_uri)
                ->where('uri', trim($uri))
                ->where('access', CalendarInstance::OWNER)
                ->firstOrFail();

            $year = (int) now()->year;
            DavWriteLock::lockCalendar((int) $instance->calendarid);
            $holiday = HolidayCalendar::query()->create([
                'calendar_id' => (int) $instance->calendarid,
                'region' => $region,
                'locale' => $locale,
                'types' => $types,
                'from_year' => $year - self::YEARS_BACK,
                'to_year' => $year + self::YEARS_AHEAD,
                'data_version' => HolidayEvents::dataVersion(),
            ]);

            $this->write($holiday, $instance);

            return $holiday;
        });
    }

    /**
     * A new region, language or set of types; the events follow.
     *
     * @param  list<string>  $types
     */
    public function update(HolidayCalendar $holiday, string $region, string $locale, array $types): HolidayChanges
    {
        $types = $this->types($types);
        $this->assertRegion($region);
        $this->assertLocale($locale);

        return $this->locked($holiday, function (HolidayCalendar $row, CalendarInstance $instance) use ($region, $locale, $types): HolidayChanges {
            $row->fill(['region' => $region, 'locale' => $locale, 'types' => $types]);

            return $this->write($row, $instance);
        });
    }

    /**
     * Makes the events again: the range moves forward to three years ahead
     * (past years stay), and a newer holiday data version is applied. Writes
     * nothing when nothing differs.
     */
    public function refresh(HolidayCalendar $holiday): HolidayChanges
    {
        return $this->locked($holiday, fn (HolidayCalendar $row, CalendarInstance $instance): HolidayChanges => $this->write($row, $instance));
    }

    /**
     * @param  callable(HolidayCalendar, CalendarInstance): HolidayChanges  $change
     */
    private function locked(HolidayCalendar $holiday, callable $change): HolidayChanges
    {
        return DB::transaction(function () use ($holiday, $change): HolidayChanges {
            DavWriteLock::lockCalendar($holiday->calendar_id);

            $row = HolidayCalendar::query()->lockForUpdate()->find($holiday->getKey());
            $instance = $row?->ownerInstance();

            if (! $row instanceof HolidayCalendar || ! $instance instanceof CalendarInstance) {
                throw new InvalidArgumentException('That collection no longer exists.');
            }

            $changes = $change($row, $instance);
            $holiday->setRawAttributes($row->getAttributes(), true);

            return $changes;
        });
    }

    private function write(HolidayCalendar $row, CalendarInstance $instance): HolidayChanges
    {
        $row->to_year = max($row->to_year, (int) now()->year + self::YEARS_AHEAD);
        $row->data_version = HolidayEvents::dataVersion();

        $calendarId = (int) $instance->calendarid;
        $id = [$calendarId, (int) $instance->getKey()];
        $wanted = $this->events->events(
            $calendarId,
            $row->region,
            $row->locale,
            $row->types,
            $row->from_year,
            $row->to_year,
            $row->created_at ?? now(),
        );
        $stored = [];

        foreach (DB::table('calendarobjects')->where('calendarid', $calendarId)->get(['uri', 'calendardata']) as $object) {
            $stored[(string) $object->uri] = BinaryValue::toString($object->calendardata);
        }

        $backend = new CalendarBackend(DB::connection()->getPdo());
        $added = $changed = $removed = 0;

        foreach ($wanted as $uri => $data) {
            if (! array_key_exists($uri, $stored)) {
                $backend->createCalendarObject($id, $uri, $data);
                $added++;
            } elseif ($stored[$uri] !== $data) {
                $backend->updateCalendarObject($id, $uri, $data);
                $changed++;
            }
        }

        foreach (array_keys(array_diff_key($stored, $wanted)) as $uri) {
            $backend->deleteCalendarObject($id, $uri);
            $removed++;
        }

        $row->refreshed_at = now();

        if (! $row->save()) {
            throw new RuntimeException('The holiday calendar could not be saved.');
        }

        return new HolidayChanges($added, $changed, $removed);
    }

    private function assertRegion(string $region): void
    {
        if (! $this->catalog->exists($region)) {
            throw new InvalidArgumentException('That country or region has no holiday data.');
        }
    }

    private function assertLocale(string $locale): void
    {
        if (! $this->catalog->isLocale($locale)) {
            throw new InvalidArgumentException('That language is not available for holiday names.');
        }
    }

    /**
     * @param  list<string>  $types
     * @return list<string>
     */
    private function types(array $types): array
    {
        $chosen = array_values(array_intersect(HolidayEvents::TYPES, $types));

        if ($chosen === [] || count($chosen) !== count(array_unique($types))) {
            throw new InvalidArgumentException('Choose at least one holiday type.');
        }

        return $chosen;
    }
}
