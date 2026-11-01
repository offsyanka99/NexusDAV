<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Dav\AddressBookBackend;
use App\Services\Dav\CalendarBackend;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\DAV\Exception\Forbidden;
use Tests\TestCase;

class DavBackendTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_keeps_laravel_identity_and_adds_sabre_storage(): void
    {
        foreach ([
            'calendars',
            'calendarinstances',
            'calendarchanges',
            'calendarobjects',
            'calendarsubscriptions',
            'schedulingobjects',
            'addressbooks',
            'addressbookchanges',
            'cards',
            'propertystorage',
            'locks',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->assertFalse(Schema::hasTable('groupmembers'));
        $this->assertFalse(Schema::hasColumn('users', 'digesta1'));
        $this->assertTrue(Schema::hasColumn('principals', 'user_id'));
        $this->assertTrue(Schema::hasColumn('principals', 'uri'));
    }

    public function test_event_and_contact_round_trip_and_sync(): void
    {
        $user = $this->user('ada', 'Ada Lovelace', 'ada@example.com');

        $this->assertSame(1, DB::table('calendars')->count());
        $this->assertSame('default', DB::table('calendarinstances')->where('principaluri', 'principals/ada')->value('uri'));
        $this->assertSame(1, DB::table('addressbooks')->count());
        $this->assertNotNull($user->principal);

        $calendars = new CalendarBackend(DB::connection()->getPdo());
        $books = new AddressBookBackend(DB::connection()->getPdo());
        $calendarId = $calendars->createCalendar('principals/ada', 'personal', [
            '{DAV:}displayname' => 'Personal',
        ]);
        $calendars->createCalendar('principals/ada', 'work', [
            '{DAV:}displayname' => 'Work',
        ]);

        $this->assertSame(3, DB::table('calendarinstances')->where('principaluri', 'principals/ada')->count());
        $this->assertSame('VEVENT,VTODO,VJOURNAL', DB::table('calendars')->where('id', $calendarId[0])->value('components'));

        $before = $calendars->getChangesForCalendar($calendarId, null, 1);
        $event = $this->event('event-1@nexusdav.test');
        $calendars->createCalendarObject($calendarId, 'planning.ics', $event);

        $stored = $calendars->getCalendarObject($calendarId, 'planning.ics');
        $this->assertIsArray($stored);
        $this->assertSame($event, $stored['calendardata']);

        $listed = $calendars->getMultipleCalendarObjects($calendarId, ['planning.ics']);
        $this->assertSame($event, $listed[0]['calendardata']);

        $changes = $calendars->getChangesForCalendar($calendarId, $before['syncToken'], 1);
        $this->assertContains('planning.ics', $changes['added']);

        $bookId = $books->createAddressBook('principals/ada', 'contacts', [
            '{DAV:}displayname' => 'Contacts',
        ]);
        $bookBefore = $books->getChangesForAddressBook($bookId, null, 1);
        $card = $this->card();
        $books->createCard($bookId, 'ada.vcf', $card);

        $storedCard = $books->getCard($bookId, 'ada.vcf');
        $this->assertIsArray($storedCard);
        $this->assertSame($card, $storedCard['carddata']);

        $listedCards = $books->getMultipleCards($bookId, ['ada.vcf']);
        $this->assertSame($card, $listedCards[0]['carddata']);

        $bookChanges = $books->getChangesForAddressBook($bookId, $bookBefore['syncToken'], 1);
        $this->assertContains('ada.vcf', $bookChanges['added']);
    }

    public function test_disabled_tasks_and_notes_are_left_out_of_new_calendars(): void
    {
        $this->user('ada', 'Ada Lovelace', 'ada@example.com');
        config([
            'nexusdav.features.tasks' => false,
            'nexusdav.features.notes' => false,
        ]);

        $calendars = new CalendarBackend(DB::connection()->getPdo());
        $this->assertSame(['VEVENT'], $calendars->allowedComponents());

        $calendarId = $calendars->createCalendar('principals/ada', 'mixed', [
            '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new SupportedCalendarComponentSet(['VEVENT', 'VTODO', 'VJOURNAL']),
        ]);

        $this->assertSame('VEVENT', DB::table('calendars')->where('id', $calendarId[0])->value('components'));
    }

    public function test_a_calendar_of_only_disabled_components_is_refused(): void
    {
        $this->user('ada', 'Ada Lovelace', 'ada@example.com');
        config(['nexusdav.features.tasks' => false]);

        $calendars = new CalendarBackend(DB::connection()->getPdo());

        $this->expectException(Forbidden::class);

        $calendars->createCalendar('principals/ada', 'tasks', [
            '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new SupportedCalendarComponentSet(['VTODO']),
        ]);
    }

    public function test_deleting_a_user_removes_only_that_users_sabre_rows(): void
    {
        $users = app(UserService::class);
        $ada = $users->create([
            'username' => 'ada_l',
            'display_name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
        ]);
        $neighbour = $users->create([
            'username' => 'adaxl',
            'display_name' => 'Neighbour',
            'email' => 'neighbour@example.com',
        ]);

        $calendars = new CalendarBackend(DB::connection()->getPdo());
        $books = new AddressBookBackend(DB::connection()->getPdo());

        $adaCalendar = $calendars->createCalendar('principals/ada_l', 'personal', [
            '{DAV:}displayname' => 'Ada',
        ]);
        $calendars->createCalendarObject($adaCalendar, 'planning.ics', $this->event('ada@nexusdav.test'));
        $calendars->createSchedulingObject('principals/ada_l', 'inbox.ics', $this->event('inbox@nexusdav.test'));

        $adaBook = $books->createAddressBook('principals/ada_l', 'contacts', [
            '{DAV:}displayname' => 'Ada',
        ]);
        $books->createCard($adaBook, 'ada.vcf', $this->card());

        DB::table('calendarsubscriptions')->insert([
            'uri' => 'feed',
            'principaluri' => 'principals/ada_l',
            'source' => 'https://example.com/ada.ics',
            'lastmodified' => time(),
        ]);
        DB::table('locks')->insert([
            'owner' => 'ada',
            'timeout' => 60,
            'created' => time(),
            'token' => 'opaquelocktoken:ada',
            'scope' => 0,
            'depth' => 1,
            'uri' => 'calendars/ada_l/personal',
        ]);
        $this->insertProperty('calendars/ada_l', 'Ada home');
        $this->insertProperty('calendars/ada_l/personal', 'Ada calendar');
        $this->insertProperty('addressbooks/ada_l/contacts', 'Ada book');

        $neighbourCalendar = $calendars->createCalendar('principals/adaxl', 'personal', [
            '{DAV:}displayname' => 'Neighbour',
        ]);
        $calendars->createCalendarObject($neighbourCalendar, 'planning.ics', $this->event('neighbour@nexusdav.test'));
        DB::table('locks')->insert([
            'owner' => 'neighbour',
            'timeout' => 60,
            'created' => time(),
            'token' => 'opaquelocktoken:neighbour',
            'scope' => 0,
            'depth' => 1,
            'uri' => 'calendars/adaxl/personal',
        ]);
        $this->insertProperty('calendars/adaxl/personal', 'Neighbour calendar');

        $users->delete($ada);

        $this->assertNull($ada->fresh());
        $this->assertNotNull($neighbour->fresh());
        $this->assertSame(0, DB::table('calendarinstances')->where('principaluri', 'principals/ada_l')->count());
        $this->assertSame(0, DB::table('calendarobjects')->where('uri', 'planning.ics')->where('calendarid', $adaCalendar[0])->count());
        $this->assertSame(0, DB::table('calendarchanges')->where('calendarid', $adaCalendar[0])->count());
        $this->assertSame(0, DB::table('schedulingobjects')->where('principaluri', 'principals/ada_l')->count());
        $this->assertSame(0, DB::table('calendarsubscriptions')->where('principaluri', 'principals/ada_l')->count());
        $this->assertSame(0, DB::table('addressbooks')->where('principaluri', 'principals/ada_l')->count());
        $this->assertSame(0, DB::table('cards')->where('addressbookid', $adaBook)->count());
        $this->assertSame(0, DB::table('addressbookchanges')->where('addressbookid', $adaBook)->count());
        $this->assertSame(0, DB::table('locks')->where('token', 'opaquelocktoken:ada')->count());
        $this->assertSame(0, DB::table('propertystorage')->where('path', 'calendars/ada_l')->count());
        $this->assertSame(0, DB::table('propertystorage')->where('path', 'calendars/ada_l/personal')->count());
        $this->assertSame(0, DB::table('propertystorage')->where('path', 'addressbooks/ada_l/contacts')->count());

        $this->assertSame(2, DB::table('calendarinstances')->where('principaluri', 'principals/adaxl')->count());
        $this->assertSame(1, DB::table('calendarobjects')->where('calendarid', $neighbourCalendar[0])->count());
        $this->assertSame(1, DB::table('locks')->where('uri', 'calendars/adaxl/personal')->count());
        $this->assertSame(1, DB::table('propertystorage')->where('path', 'calendars/adaxl/personal')->count());
    }

    private function user(string $username, string $displayName, string $email): User
    {
        return app(UserService::class)->create([
            'username' => $username,
            'display_name' => $displayName,
            'email' => $email,
        ]);
    }

    private function insertProperty(string $path, string $value): void
    {
        $statement = DB::connection()->getPdo()->prepare(
            'insert into propertystorage (path, name, valuetype, value) values (?, ?, ?, ?)',
        );
        $statement->bindValue(1, $path);
        $statement->bindValue(2, '{DAV:}displayname');
        $statement->bindValue(3, 1, PDO::PARAM_INT);
        $statement->bindValue(4, $value, PDO::PARAM_LOB);
        $statement->execute();
    }

    private function event(string $uid): string
    {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//NexusDAV//EN',
            'BEGIN:VEVENT',
            'UID:'.$uid,
            'DTSTAMP:20261028T120000Z',
            'DTSTART:20261028T130000Z',
            'DTEND:20261028T140000Z',
            'SUMMARY:Planning',
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);
    }

    private function card(): string
    {
        return implode("\r\n", [
            'BEGIN:VCARD',
            'VERSION:3.0',
            'UID:ada-card',
            'FN:Ada Lovelace',
            'N:Lovelace;Ada;;;',
            'EMAIL:ada@example.com',
            'END:VCARD',
            '',
        ]);
    }
}
