<?php

namespace Tests\Feature;

use App\Services\Dav\SharingService;
use App\Services\Portal\CollectionRef;
use App\Services\Portal\Exceptions\NotFound;
use App\Services\Portal\PortalCollections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PortalHelpers;
use Tests\TestCase;

/**
 * PR 50a, step 2: the calendars and address books a user sees in the
 * portal, their access and why one is read-only.
 */
class PortalCollectionsTest extends TestCase
{
    use PortalHelpers, RefreshDatabase;

    public function test_a_user_sees_their_own_collections_as_owner(): void
    {
        $ada = $this->user('ada');
        $collections = app(PortalCollections::class);

        $calendars = $collections->calendars($ada);
        $this->assertCount(1, $calendars);
        $calendar = $calendars[0];
        $this->assertSame(CollectionRef::CALENDAR, $calendar->type);
        $this->assertSame($this->calendarId($ada), $calendar->id);
        $this->assertSame('default', $calendar->uri);
        $this->assertSame('Calendar', $calendar->name);
        $this->assertSame('#1A73E8', $calendar->color);
        $this->assertSame(['VEVENT', 'VTODO', 'VJOURNAL'], $calendar->components);
        $this->assertSame(CollectionRef::OWNER, $calendar->access);
        $this->assertTrue($calendar->isWritable());
        $this->assertSame([$calendar->id, (int) $calendar->instanceId], $calendar->backendId());
        $this->assertSame('calendars/ada/default', $calendar->davPath());

        $books = $collections->addressBooks($ada);
        $this->assertCount(1, $books);
        $this->assertSame($this->addressBookId($ada), $books[0]->id);
        $this->assertNull($books[0]->instanceId);
        $this->assertSame('Contacts', $books[0]->name);
        $this->assertTrue($books[0]->isWritable());
        $this->assertSame($books[0]->id, $books[0]->backendId());
        $this->assertCount(2, $collections->all($ada));
    }

    public function test_shares_carry_their_access_and_a_read_only_share_is_read_only(): void
    {
        $ada = $this->user('ada');
        $bob = $this->user('bob');
        $cleo = $this->user('cleo');
        $this->shareCalendar($ada, $bob, SharingService::READ);
        $this->shareCalendar($ada, $cleo, SharingService::READ_WRITE);
        $this->shareAddressBook($ada, $bob, SharingService::READ);
        $this->shareAddressBook($ada, $cleo, SharingService::READ_WRITE);
        $collections = app(PortalCollections::class);

        $read = $collections->find($bob, CollectionRef::CALENDAR, $this->calendarId($ada));
        $this->assertSame(CollectionRef::READ, $read->access);
        $this->assertFalse($read->isWritable());
        $this->assertSame([CollectionRef::REASON_READ_ONLY_SHARE], $read->readOnlyReasons);
        $this->assertSame($read->readOnlyReasons, $collections->readOnlyReasons($read));
        $this->assertSame('principals/bob', $read->principalUri);

        $write = $collections->find($cleo, CollectionRef::CALENDAR, $this->calendarId($ada));
        $this->assertSame(CollectionRef::READ_WRITE, $write->access);
        $this->assertTrue($write->isWritable());

        $readBook = $collections->find($bob, CollectionRef::ADDRESS_BOOK, $this->addressBookId($ada));
        $this->assertFalse($readBook->isWritable());
        $this->assertNotNull($readBook->instanceId);
        $this->assertTrue($collections->find($cleo, CollectionRef::ADDRESS_BOOK, $this->addressBookId($ada))->isWritable());
    }

    public function test_another_users_or_a_missing_collection_is_not_found(): void
    {
        $ada = $this->user('ada');
        $bob = $this->user('bob');
        $collections = app(PortalCollections::class);

        foreach ([
            [CollectionRef::CALENDAR, $this->calendarId($ada)],
            [CollectionRef::ADDRESS_BOOK, $this->addressBookId($ada)],
            [CollectionRef::CALENDAR, 999999],
            ['other', $this->calendarId($bob)],
        ] as [$type, $id]) {
            try {
                $collections->find($bob, $type, $id);
                $this->fail('Found '.$type.' '.$id.' for bob.');
            } catch (NotFound $exception) {
                $this->assertSame('Not found.', $exception->getMessage());
            }
        }
    }

    public function test_the_switches_hide_types_and_shares_and_limit_components(): void
    {
        $ada = $this->user('ada');
        $bob = $this->user('bob');
        $this->shareCalendar($ada, $bob, SharingService::READ_WRITE);
        $this->shareAddressBook($ada, $bob, SharingService::READ_WRITE);
        $collections = app(PortalCollections::class);

        $this->assertCount(2, $collections->calendars($bob));
        $this->assertCount(2, $collections->addressBooks($bob));

        config(['nexusdav.features.sharing' => false]);
        $this->assertSame([$this->calendarId($bob)], array_map(fn ($ref) => $ref->id, $collections->calendars($bob)));
        $this->assertSame([$this->addressBookId($bob)], array_map(fn ($ref) => $ref->id, $collections->addressBooks($bob)));
        config(['nexusdav.features.sharing' => true]);

        config(['nexusdav.features.tasks' => false]);
        $calendar = $collections->find($ada, CollectionRef::CALENDAR, $this->calendarId($ada));
        $this->assertSame(['VEVENT', 'VJOURNAL'], $calendar->components);
        // Tasks off is not a reason on the calendar: it still takes events.
        $this->assertTrue($calendar->isWritable());

        config(['nexusdav.features.notes' => false]);
        $this->assertSame(['VEVENT'], $collections->find($ada, CollectionRef::CALENDAR, $this->calendarId($ada))->components);

        config(['nexusdav.features.caldav' => false]);
        $this->assertSame([], $collections->calendars($ada));
        $this->assertCount(1, $collections->addressBooks($ada));

        config(['nexusdav.features.carddav' => false]);
        $this->assertSame([], $collections->all($ada));
    }

    public function test_a_calendar_flagged_as_holiday_calendar_is_read_only_for_its_owner(): void
    {
        $ada = $this->user('ada');
        $this->flagHoliday($this->calendarId($ada));

        $ref = app(PortalCollections::class)->find($ada, CollectionRef::CALENDAR, $this->calendarId($ada));

        $this->assertTrue($ref->holiday);
        $this->assertTrue($ref->isOwner());
        $this->assertFalse($ref->isWritable());
        $this->assertSame([CollectionRef::REASON_HOLIDAY], $ref->readOnlyReasons);
    }
}
