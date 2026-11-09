<?php

namespace App\Services\Dav;

use App\Models\User;
use App\Services\Push\PushChanges;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Sabre\CalDAV\Backend\PDO as SabreCalendarBackend;
use Sabre\CalDAV\Plugin as CalDavPlugin;
use Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\MethodNotAllowed;
use Sabre\DAV\PropPatch;
use Sabre\DAV\Sharing\Plugin as SharingPlugin;
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

        // A subscription of the same name is in another table, with its own
        // unique key. Two children of one name would hide each other. The
        // instance table is read again too: a share may have taken the name
        // since the caller looked, and sabre's insert would end in a 500.
        HomeNamespace::lock((string) $principalUri);

        if (DB::table('calendarsubscriptions')->where('principaluri', $principalUri)->where('uri', $calendarUri)->exists()
            || DB::table('calendarinstances')->where('principaluri', $principalUri)->where('uri', $calendarUri)->exists()) {
            throw new MethodNotAllowed('The resource you tried to create already exists');
        }

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

        $id = parent::createCalendar($principalUri, $calendarUri, $properties);
        app(PushChanges::class)->homeChanged(true, (string) $principalUri);

        return $id;
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
            // Sharing off keeps the rows and leaves the sharee's copy out of the home.
            if (! $this->enabled('sharing') && (int) ($calendar['share-access'] ?? SharingPlugin::ACCESS_SHAREDOWNER) !== SharingPlugin::ACCESS_SHAREDOWNER) {
                continue;
            }

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
        $row = DB::table('calendars')->where('id', $id)->first(['synctoken', 'synctoken_min']);

        if ($row === null) {
            return null;
        }

        $from = null;

        if (! SyncToken::isInitial($syncToken)) {
            // A share can reuse a URI the client already synced. A plain number
            // would read this calendar from the old collection's position.
            // Owned calendars still accept one (Decision 20).
            if (preg_match('/^[1-9][0-9]{0,9}$/', (string) $syncToken) === 1 && $this->isShare($calendarId)) {
                return null;
            }

            $from = SyncToken::resolve($syncToken, $id, (int) $row->synctoken, (int) $row->synctoken_min);

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

    protected function addChange($calendarId, $objectUri, $operation)
    {
        parent::addChange($calendarId, $objectUri, $operation);

        app(SyncHistoryPruner::class)->afterWrite();
        app(PushChanges::class)->calendarChanged((int) $calendarId, (string) $objectUri);
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

    /**
     * sabre deletes every instance when the owner deletes the calendar, and
     * only the sharee's instance when a sharee does. The sharees' locks and
     * properties live under their own paths, so they are removed here. The
     * owner's delete is the calendar's last push message; a sharee's delete
     * only changes their own home.
     */
    public function deleteCalendar($calendarId)
    {
        if (is_array($calendarId) && count($calendarId) === 2) {
            [$id, $instanceId] = $calendarId;
            $instance = DB::table('calendarinstances')->where('id', $instanceId)->first(['access', 'principaluri', 'uri']);

            if ($instance !== null && (int) $instance->access === SharingPlugin::ACCESS_SHAREDOWNER) {
                $principals = DB::table('calendarinstances')->where('calendarid', $id)->pluck('principaluri')
                    ->map(static fn (mixed $principal): string => (string) $principal)->all();
                app(PushChanges::class)->collectionDeleted(true, (int) $id, $principals);

                $shares = DB::table('calendarinstances')
                    ->where('calendarid', $id)
                    ->where('access', '!=', SharingPlugin::ACCESS_SHAREDOWNER)
                    ->get(['principaluri', 'uri']);

                foreach ($shares as $share) {
                    app(DavDataCleaner::class)->deletePath(SharingService::sharePath(true, (string) $share->principaluri, (string) $share->uri));
                }
            } elseif ($instance !== null) {
                app(DavDataCleaner::class)->deletePath(SharingService::sharePath(true, (string) $instance->principaluri, (string) $instance->uri));
                app(PushChanges::class)->homeChanged(true, (string) $instance->principaluri);
            }
        }

        parent::deleteCalendar($calendarId);
    }

    public function updateCalendar($calendarId, PropPatch $propPatch)
    {
        DavLimits::refuse($propPatch, DavLimits::CALENDAR);

        // sabre records a calendarchanges row for every instance property.
        // A sharee's name, color and order live on their row and must not
        // move the owner's sync token.
        if ($this->isShare($calendarId)) {
            $this->updateShareInstance($calendarId, $propPatch);
        }

        parent::updateCalendar($calendarId, $propPatch);
    }

    /**
     * A received share, access 2 or 3. The owner's instance is access 1.
     */
    private function isShare(mixed $calendarId): bool
    {
        if (! is_array($calendarId) || ! isset($calendarId[1])) {
            return false;
        }

        $access = DB::table('calendarinstances')->where('id', $calendarId[1])->value('access');

        return $access !== null && (int) $access !== SharingPlugin::ACCESS_SHAREDOWNER;
    }

    /**
     * Writes the sharee's instance columns and claims the properties so
     * sabre's update does not record a change.
     *
     * @param  array<int, mixed>  $calendarId
     */
    private function updateShareInstance(array $calendarId, PropPatch $propPatch): void
    {
        $instanceId = (int) $calendarId[1];
        $supported = array_keys($this->propertyMap);
        $supported[] = '{'.CalDavPlugin::NS_CALDAV.'}schedule-calendar-transp';

        $propPatch->handle($supported, function (array $mutations) use ($instanceId): bool {
            $values = [];

            foreach ($mutations as $propertyName => $propertyValue) {
                if ($propertyName === '{'.CalDavPlugin::NS_CALDAV.'}schedule-calendar-transp') {
                    if (! $propertyValue instanceof ScheduleCalendarTransp) {
                        return false;
                    }

                    $values['transparent'] = $propertyValue->getValue() === 'transparent' ? 1 : 0;

                    continue;
                }

                $column = $this->propertyMap[$propertyName] ?? null;

                if (! is_string($column) || (! is_scalar($propertyValue) && $propertyValue !== null)) {
                    return false;
                }

                // The column is an integer. A PROPPATCH value arrives as text.
                $values[$column] = $column === 'calendarorder' ? (int) $propertyValue : $propertyValue;
            }

            if ($values !== []) {
                DB::table('calendarinstances')->where('id', $instanceId)->update($values);
            }

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function createSubscription($principalUri, $uri, array $properties)
    {
        DavLimits::assertName($uri, 'The subscription name');
        DavLimits::assertProperties($properties, DavLimits::SUBSCRIPTION);

        HomeNamespace::lock((string) $principalUri);

        if (DB::table('calendarinstances')->where('principaluri', $principalUri)->where('uri', $uri)->exists()) {
            throw new MethodNotAllowed('The resource you tried to create already exists');
        }

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
