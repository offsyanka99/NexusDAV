<?php

namespace App\Services\Portal;

use App\Models\User;
use App\Services\Dav\CalendarBackend;
use Illuminate\Support\Facades\DB;
use Sabre\VObject\Component\VCalendar;

/**
 * Events, tasks and notes (VEVENT, VTODO, VJOURNAL) of the user's calendars,
 * written through CalendarBackend like a CalDAV PUT or DELETE. A calendar
 * the user may only read, a holiday calendar, a component the calendar
 * does not take now (Tasks or Notes off) and another user's calendar are
 * refused.
 */
final class CalendarItemStore extends ItemStore
{
    /**
     * A new item from a form: a random UUID as its UID and <uid>.ics as its
     * URI (Decision 13).
     */
    public function createFromForm(User $user, int $calendarId, VCalendar $calendar): ItemResult
    {
        $uid = $this->newUid();

        foreach ($calendar->getComponents() as $component) {
            if ($component->name !== 'VTIMEZONE') {
                $component->remove('UID');
                $component->add('UID', $uid);
            }
        }

        return $this->create($user, $calendarId, $uid.'.ics', $calendar->serialize());
    }

    protected function type(): string
    {
        return CollectionRef::CALENDAR;
    }

    protected function extension(): string
    {
        return '.ics';
    }

    protected function validate(string $data, CollectionRef $ref): ValidatedItem
    {
        return $this->validator->calendar($data, $ref);
    }

    protected function current(CollectionRef $ref, string $uri): ?array
    {
        $row = DB::table('calendarobjects')->where('calendarid', $ref->id)->where('uri', $uri)->first(['etag', 'uid']);

        if ($row === null) {
            return null;
        }

        return ['etag' => '"'.$row->etag.'"', 'uid' => is_string($row->uid) ? $row->uid : null];
    }

    protected function byUid(CollectionRef $ref): array
    {
        $objects = [];

        foreach (DB::table('calendarobjects')->where('calendarid', $ref->id)->select(['id', 'uri', 'uid', 'etag'])->lazyById(1000) as $row) {
            if (is_string($row->uid) && ! isset($objects[$row->uid])) {
                $objects[$row->uid] = ['uri' => (string) $row->uri, 'etag' => '"'.$row->etag.'"'];
            }
        }

        return $objects;
    }

    protected function storedUris(CollectionRef $ref): array
    {
        return DB::table('calendarobjects')->where('calendarid', $ref->id)->pluck('uri')->map(static fn (mixed $uri): string => (string) $uri)->all();
    }

    protected function uidTakenBy(CollectionRef $ref, string $uid, string $exceptUri): ?string
    {
        $uri = DB::table('calendarobjects')
            ->where('calendarid', $ref->id)
            ->where('uid', $uid)
            ->where('uri', '!=', $exceptUri)
            ->value('uri');

        return is_string($uri) ? $uri : null;
    }

    protected function backendCreate(CollectionRef $ref, string $uri, string $data): string
    {
        return (string) $this->backend()->createCalendarObject($ref->backendId(), $uri, $data);
    }

    protected function backendUpdate(CollectionRef $ref, string $uri, string $data): string
    {
        return (string) $this->backend()->updateCalendarObject($ref->backendId(), $uri, $data);
    }

    protected function backendDelete(CollectionRef $ref, string $uri): void
    {
        $this->backend()->deleteCalendarObject($ref->backendId(), $uri);
    }

    private function backend(): CalendarBackend
    {
        return app(CalendarBackend::class);
    }
}
