<?php

namespace App\Services\Dav;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Sabre\CalDAV\Backend\PDO as SabreCalendarBackend;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\PropPatch;
use Sabre\DAV\Sync\Plugin as SyncPlugin;

/**
 * sabre's PDO calendar backend on Laravel's connection.
 * Bytea values are turned back into the stored string.
 * The tasks and notes switches limit VTODO and VJOURNAL: a new calendar
 * cannot ask for them, and an existing calendar does not offer them, so a
 * client cannot add or change one. Stored tasks and notes stay readable.
 * A new calendar gets the owner's time zone (or the server's) as its
 * calendar-timezone. Sync tokens and the ctag carry the calendar's id
 * (SyncToken).
 */
final class CalendarBackend extends SabreCalendarBackend
{
    private const SYNC_TOKEN = '{http://sabredav.org/ns}sync-token';

    private const CTAG = '{http://calendarserver.org/ns/}getctag';

    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function createCalendar($principalUri, $calendarUri, array $properties)
    {
        DavLimits::assertName($calendarUri, 'The calendar name');
        DavLimits::assertProperties($properties, DavLimits::CALENDAR);

        $name = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';
        $allowed = $this->allowedComponents();

        if (! isset($properties[$name])) {
            $properties[$name] = new SupportedCalendarComponentSet($allowed);
        } elseif ($properties[$name] instanceof SupportedCalendarComponentSet) {
            $filtered = [];

            foreach ($properties[$name]->getValue() as $component) {
                if (is_string($component) && in_array($component, $allowed, true)) {
                    $filtered[] = $component;
                }
            }

            if ($filtered === []) {
                throw new Forbidden('None of the requested calendar components are enabled.');
            }

            $properties[$name] = new SupportedCalendarComponentSet($filtered);
        }

        $timezone = '{urn:ietf:params:xml:ns:caldav}calendar-timezone';

        if (! isset($properties[$timezone])) {
            $properties[$timezone] = CalendarTimezone::vcalendar($this->timezoneFor((string) $principalUri));
        }

        return parent::createCalendar($principalUri, $calendarUri, $properties);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getCalendarsForUser($principalUri)
    {
        $name = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';
        $allowed = $this->allowedComponents();
        $calendars = [];

        foreach (parent::getCalendarsForUser($principalUri) as $calendar) {
            if (isset($calendar[$name]) && $calendar[$name] instanceof SupportedCalendarComponentSet) {
                $calendar[$name] = new SupportedCalendarComponentSet(array_values(array_filter(
                    $calendar[$name]->getValue(),
                    static fn (mixed $component): bool => is_string($component) && in_array($component, $allowed, true),
                )));
            }

            $token = SyncToken::format((int) $calendar['id'][0], (int) $calendar[self::SYNC_TOKEN]);
            $calendar[self::SYNC_TOKEN] = $token;
            $calendar[self::CTAG] = SyncPlugin::SYNCTOKEN_PREFIX.$token;

            $calendars[] = $calendar;
        }

        return $calendars;
    }

    /**
     * Checks the client's token against this calendar before sabre reads the
     * change log, and returns the new token with the calendar's id.
     *
     * sabre passes [calendar id, instance id] here.
     *
     * @return array<string, mixed>|null
     */
    public function getChangesForCalendar($calendarId, $syncToken, $syncLevel, $limit = null)
    {
        if (! is_array($calendarId)) {
            throw new InvalidArgumentException('The value passed to $calendarId is expected to be an array with a calendarId and an instanceId');
        }

        $id = (int) $calendarId[0];
        $current = DB::table('calendars')->where('id', $id)->value('synctoken');

        if ($current === null) {
            return null;
        }

        $from = null;

        if (! SyncToken::isInitial($syncToken)) {
            $from = SyncToken::resolve($syncToken, $id, (int) $current);

            if ($from === null) {
                return null;
            }
        }

        $result = parent::getChangesForCalendar($calendarId, $from === null ? null : (string) $from, $syncLevel, $limit);

        if (! is_array($result)) {
            return null;
        }

        $result['syncToken'] = SyncToken::format($id, (int) $result['syncToken']);

        return $result;
    }

    /**
     * The owner's time zone, or the server's when the owner has none or it is not valid.
     */
    public function timezoneFor(string $principalUri): string
    {
        $username = str_starts_with($principalUri, 'principals/') ? substr($principalUri, strlen('principals/')) : '';
        $user = $username === '' ? null : User::query()->where('username', $username)->first();
        $zones = timezone_identifiers_list();

        foreach ([$user?->timezone, config('nexusdav.default_timezone')] as $candidate) {
            if (is_string($candidate) && in_array($candidate, $zones, true)) {
                return $candidate;
            }
        }

        return 'UTC';
    }

    public function updateCalendar($calendarId, PropPatch $propPatch)
    {
        DavLimits::refuse($propPatch, DavLimits::CALENDAR);

        parent::updateCalendar($calendarId, $propPatch);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function createSubscription($principalUri, $uri, array $properties)
    {
        DavLimits::assertName($uri, 'The subscription name');
        DavLimits::assertProperties($properties, DavLimits::SUBSCRIPTION);

        return parent::createSubscription($principalUri, $uri, $properties);
    }

    public function updateSubscription($subscriptionId, PropPatch $propPatch)
    {
        DavLimits::refuse($propPatch, DavLimits::SUBSCRIPTION);

        parent::updateSubscription($subscriptionId, $propPatch);
    }

    public function createCalendarObject($calendarId, $objectUri, $calendarData)
    {
        DavLimits::assertName($objectUri, 'The resource name');

        return parent::createCalendarObject($calendarId, $objectUri, $calendarData);
    }

    /**
     * Runs for every create and update of an object. calendarobjects.uid is
     * 200 characters.
     *
     * @return array<string, mixed>
     */
    protected function getDenormalizedData($calendarData)
    {
        $data = parent::getDenormalizedData($calendarData);
        DavLimits::assertName($data['uid'] ?? null, 'The UID');

        return $data;
    }

    /**
     * Insert this calendar when the principal does not already have the URI.
     * The unique key is (principaluri, uri) on the instance. insertOrIgnore
     * does not abort a PostgreSQL transaction. calendars has no such key, so
     * a rejected instance drops the unused calendar row.
     */
    public function ensureCalendar(string $principalUri, string $calendarUri, string $displayName, ?string $color): void
    {
        $calendarId = DB::table('calendars')->insertGetId([
            'synctoken' => 1,
            'components' => implode(',', $this->allowedComponents()),
        ]);

        $values = [
            'calendarid' => $calendarId,
            'principaluri' => $principalUri,
            'uri' => $calendarUri,
            'transparent' => 0,
            'displayname' => $displayName,
            'timezone' => CalendarTimezone::vcalendar($this->timezoneFor($principalUri)),
        ];

        if ($color !== null) {
            $values['calendarcolor'] = $color;
        }

        $inserted = DB::table('calendarinstances')->insertOrIgnore($values);

        if ($inserted === 0) {
            DB::table('calendars')->where('id', $calendarId)->delete();
        }
    }

    /**
     * @param  list<string>  $components
     */
    public function createCollection(string $principalUri, string $uri, string $displayName, ?string $description, string $color, array $components): void
    {
        $properties = $this->columnProperties([
            'displayname' => $displayName,
            'description' => $description,
            'calendarcolor' => $color,
        ], false);
        $properties['{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set'] = new SupportedCalendarComponentSet($components);

        $this->createCalendar($principalUri, $uri, $properties);
    }

    public function updateCollection(int $calendarId, int $instanceId, string $displayName, ?string $description, string $color): void
    {
        $patch = new PropPatch($this->columnProperties([
            'displayname' => $displayName,
            'description' => $description,
            'calendarcolor' => $color,
        ], true));
        $this->updateCalendar([$calendarId, $instanceId], $patch);

        if ($patch->commit() !== true) {
            throw new RuntimeException('The calendar could not be saved.');
        }
    }

    /**
     * @param  list<string>  $components
     */
    public function setComponents(int $calendarId, array $components): void
    {
        $ordered = [];

        foreach (['VEVENT', 'VTODO', 'VJOURNAL'] as $name) {
            if (in_array($name, $components, true)) {
                $ordered[] = $name;
            }
        }

        if (! in_array('VEVENT', $ordered, true)) {
            array_unshift($ordered, 'VEVENT');
        }

        $stored = implode(',', $ordered);
        $current = DB::table('calendars')->where('id', $calendarId)->value('components');

        if ($current === $stored) {
            return;
        }

        // PROPFIND reads this column directly. A synctoken bump with no
        // calendarchanges row leaves the column ahead of the change log, and
        // the next sync report repeats the old token.
        DB::table('calendars')->where('id', $calendarId)->update([
            'components' => $stored,
        ]);
    }

    /**
     * @param  array<string, string|null>  $columns
     * @return array<string, string|null>
     */
    private function columnProperties(array $columns, bool $keepNull): array
    {
        $properties = [];

        foreach ($columns as $column => $value) {
            if (! $keepNull && $value === null) {
                continue;
            }

            $xml = array_search($column, $this->propertyMap, true);

            if (! is_string($xml)) {
                throw new RuntimeException('The calendar backend has no '.$column.' column.');
            }

            $properties[$xml] = $value;
        }

        return $properties;
    }

    /**
     * @return list<string>
     */
    public function allowedComponents(): array
    {
        $components = ['VEVENT'];

        if ($this->enabled('tasks')) {
            $components[] = 'VTODO';
        }

        if ($this->enabled('notes')) {
            $components[] = 'VJOURNAL';
        }

        return $components;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCalendarObject($calendarId, $objectUri)
    {
        $object = parent::getCalendarObject($calendarId, $objectUri);

        return $this->normalize($object, 'calendardata');
    }

    /**
     * @param  list<string>  $uris
     * @return list<array<string, mixed>>
     */
    public function getMultipleCalendarObjects($calendarId, array $uris)
    {
        return $this->normalizeList(parent::getMultipleCalendarObjects($calendarId, $uris), 'calendardata');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSchedulingObject($principalUri, $objectUri)
    {
        return $this->normalize(parent::getSchedulingObject($principalUri, $objectUri), 'calendardata');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSchedulingObjects($principalUri)
    {
        return $this->normalizeList(parent::getSchedulingObjects($principalUri), 'calendardata');
    }

    private function enabled(string $feature): bool
    {
        return filter_var(config('nexusdav.features.'.$feature, true), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  array<string, mixed>|null  $row
     * @return array<string, mixed>|null
     */
    private function normalize(?array $row, string $column): ?array
    {
        if (! is_array($row) || ! array_key_exists($column, $row)) {
            return $row;
        }

        $row[$column] = BinaryValue::toString($row[$column]);

        return $row;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function normalizeList(array $rows, string $column): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            $item = $this->normalize($row, $column);

            if (is_array($item)) {
                $normalized[] = $item;
            }
        }

        return $normalized;
    }
}
