<?php

namespace Tests\Feature;

use App\Models\AddressBook;
use App\Models\AddressBookInstance;
use App\Models\CalendarInstance;
use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\Dav\AddressBookBackend;
use App\Services\Dav\CalendarBackend;
use App\Services\Dav\CollectionService;
use App\Services\Dav\SharingService;
use App\Services\ServerCounts;
use App\Services\UserService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Sabre\DAV\Exception\MethodNotAllowed;
use Sabre\DAV\Xml\Property\Href;
use Tests\TestCase;

/**
 * PR 21: share storage and SharingService (docs/design/sharing.md). Runs on
 * SQLite and PostgreSQL in CI. Nothing here goes through the panel, and no
 * DAV request reaches a share: PR 23 to PR 25 serve them.
 */
class SharingServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $ada;

    private User $bob;

    private User $carol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ada = $this->user('ada', 'Ada Lovelace');
        $this->bob = $this->user('bob', 'Bob Baker');
        $this->carol = $this->user('carol', 'Carol Clark');
    }

    public function test_a_calendar_share_is_an_accepted_instance_with_the_owners_settings(): void
    {
        $owner = $this->calendar($this->ada);
        DB::table('calendarinstances')->where('id', $owner->getKey())->update(['description' => 'Team', 'calendarorder' => 4, 'calendarcolor' => '#112233']);
        $owner->refresh();

        $share = $this->sharing()->share($owner, $this->bob, SharingService::READ);

        $this->assertInstanceOf(CalendarInstance::class, $share);
        $row = DB::table('calendarinstances')->where('id', $share->getKey())->first();
        $this->assertNotNull($row);
        $this->assertSame((int) $owner->calendarid, (int) $row->calendarid);
        $this->assertSame('principals/bob', $row->principaluri);
        $this->assertSame(SharingService::READ, (int) $row->access);
        $this->assertSame('default-ada', $row->uri, 'Bob already has a calendar named default.');
        $this->assertSame('Calendar (Ada Lovelace)', $row->displayname);
        $this->assertSame('Team', $row->description);
        $this->assertSame(4, (int) $row->calendarorder);
        $this->assertSame('#112233', $row->calendarcolor);
        $this->assertSame($owner->timezone, $row->timezone);
        $this->assertSame(0, (int) $row->transparent);
        $this->assertSame('principals/bob', $row->share_href);
        $this->assertSame('Bob Baker', $row->share_displayname);
        $this->assertSame(2, (int) $row->share_invitestatus);

        // The owner's own row and the calendar are untouched.
        $this->assertSame(CalendarInstance::OWNER, (int) DB::table('calendarinstances')->where('id', $owner->getKey())->value('access'));
        $this->assertSame(1, DB::table('calendars')->where('id', $owner->calendarid)->count());
    }

    public function test_an_address_book_share_is_an_instance_row_linked_to_the_owners_book(): void
    {
        $book = $this->book($this->ada);
        DB::table('addressbooks')->where('id', $book->getKey())->update(['description' => 'Clients']);
        $book->refresh();

        $share = $this->sharing()->share($book, $this->bob, SharingService::READ_WRITE);

        $this->assertInstanceOf(AddressBookInstance::class, $share);
        $this->assertSame($book->getKey(), $share->addressbookid);
        $this->assertSame('principals/bob', $share->principaluri);
        $this->assertSame('default-ada', $share->uri);
        $this->assertSame('Contacts (Ada Lovelace)', $share->displayname);
        $this->assertSame('Clients', $share->description);
        $this->assertSame(SharingService::READ_WRITE, $share->access);
        $this->assertSame('principals/bob', $share->share_href);
        $this->assertSame('Bob Baker', $share->share_displayname);
        $this->assertSame(2, $share->share_invitestatus);
        $this->assertSame(1, DB::table('addressbooks')->where('principaluri', 'principals/ada')->count(), 'The owner gets no instance row.');
    }

    public function test_the_owners_uri_is_kept_when_the_sharee_does_not_use_it(): void
    {
        app(CollectionService::class)->createAddressBook($this->ada, 'clients', 'Clients', null);
        $book = AddressBook::query()->where('principaluri', 'principals/ada')->where('uri', 'clients')->firstOrFail();

        $this->assertSame('clients', $this->sharing()->share($book, $this->bob, SharingService::READ)->uri);
    }

    public function test_every_name_in_the_sharees_home_counts_and_the_suffix_counts_up(): void
    {
        app(CollectionService::class)->createCalendar($this->bob, 'default-ada', 'Taken', null, '#000000', false, false);

        $this->assertSame('default-ada-2', $this->sharing()->share($this->calendar($this->ada), $this->bob, SharingService::READ)->uri);

        // A subscription and a received share hold names in the calendar home too.
        $this->subscribe($this->carol, 'default');
        $this->assertSame('default-ada', $this->sharing()->share($this->calendar($this->ada), $this->carol, SharingService::READ)->uri);
        $this->assertSame('default-bob', $this->sharing()->share($this->calendar($this->bob), $this->carol, SharingService::READ)->uri);

        // Address books: the sharee's own books and received shares.
        app(CollectionService::class)->createAddressBook($this->carol, 'default-ada', 'Taken', null);
        $this->assertSame('default-ada-2', $this->sharing()->share($this->book($this->ada), $this->carol, SharingService::READ)->uri);
    }

    public function test_a_long_uri_is_shortened_from_the_end_to_fit_the_suffix(): void
    {
        $long = str_repeat('a', 200);
        app(CollectionService::class)->createAddressBook($this->ada, $long, 'Long', null);
        app(CollectionService::class)->createAddressBook($this->bob, $long, 'Mine', null);
        $book = AddressBook::query()->where('principaluri', 'principals/ada')->where('uri', $long)->firstOrFail();

        $uri = $this->sharing()->share($book, $this->bob, SharingService::READ)->uri;

        $this->assertSame(str_repeat('a', 196).'-ada', $uri);
        $this->assertSame(200, mb_strlen($uri));
    }

    public function test_the_initial_name_is_cut_to_255_characters(): void
    {
        $this->assertSame('Calendar (Ada)', SharingService::shareName('Calendar', 'Ada'));
        $this->assertSame('Calendar', SharingService::shareName('Calendar', '  '));

        // The owner's name is shortened first.
        $name = SharingService::shareName('Team', str_repeat('O', 300));
        $this->assertSame(255, mb_strlen($name));
        $this->assertSame('Team ('.str_repeat('O', 248).')', $name);

        // Nothing of the owner's name fits: no parentheses, the name is cut.
        $this->assertSame(str_repeat('ж', 252), SharingService::shareName(str_repeat('ж', 252), 'Ada'));
        $this->assertSame(str_repeat('n', 255), SharingService::shareName(str_repeat('n', 400), 'Ada'));

        $this->ada->forceFill(['display_name' => str_repeat('A', 255)])->save();
        $this->bob->forceFill(['display_name' => str_repeat('B', 255)])->save();
        $share = $this->sharing()->share($this->calendar($this->ada), $this->bob, SharingService::READ);
        $row = DB::table('calendarinstances')->where('id', $share->getKey())->first();
        $this->assertSame(255, mb_strlen((string) $row?->displayname));
        $this->assertSame(str_repeat('B', 100), $row?->share_displayname);
    }

    public function test_sharing_again_changes_the_access_of_the_same_row(): void
    {
        $book = $this->book($this->ada);
        $first = $this->sharing()->share($book, $this->bob, SharingService::READ);
        $second = $this->sharing()->share($book, $this->bob, SharingService::READ_WRITE);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(SharingService::READ_WRITE, (int) $second->getAttribute('access'));
        $this->assertSame(SharingService::READ_WRITE, (int) AddressBookInstance::query()->value('access'));
        $this->assertSame(1, AddressBookInstance::query()->count());

        $this->sharing()->changeAccess($book, $this->bob, SharingService::READ);
        $this->assertSame(SharingService::READ, (int) AddressBookInstance::query()->value('access'));

        $calendar = $this->calendar($this->ada);
        $this->sharing()->share($calendar, $this->bob, SharingService::READ);
        $this->sharing()->changeAccess($calendar, $this->bob, SharingService::READ_WRITE);
        $this->assertSame(SharingService::READ_WRITE, (int) DB::table('calendarinstances')->where('principaluri', 'principals/bob')->where('access', '>', 1)->value('access'));
        $this->assertSame(CalendarInstance::OWNER, (int) DB::table('calendarinstances')->where('id', $calendar->getKey())->value('access'));

        $this->expectExceptionMessage('That user has no share of this collection.');
        $this->sharing()->changeAccess($book, $this->carol, SharingService::READ_WRITE);
    }

    public function test_the_rules_are_enforced(): void
    {
        $calendar = $this->calendar($this->ada);
        $book = $this->book($this->ada);

        $this->assertRefused('A collection cannot be shared with its owner.', fn () => $this->sharing()->share($calendar, $this->ada, SharingService::READ));
        $this->assertRefused('A collection cannot be shared with its owner.', fn () => $this->sharing()->share($book, $this->ada, SharingService::READ));

        foreach ([0, 1, 4, 5] as $access) {
            $this->assertRefused('Access must be read only or read and write.', fn () => $this->sharing()->share($book, $this->bob, $access));
        }

        $share = $this->sharing()->share($calendar, $this->bob, SharingService::READ);
        $this->assertInstanceOf(CalendarInstance::class, $share);
        $this->assertRefused('Only the owner\'s calendar can be shared.', fn () => $this->sharing()->share($share, $this->carol, SharingService::READ));
        $this->assertRefused('That collection no longer exists.', fn () => $this->sharing()->share(new AddressBook, $this->bob, SharingService::READ));

        $this->assertSame(0, AddressBookInstance::query()->count());
        $this->assertSame(1, DB::table('calendarinstances')->where('access', '>', 1)->count());
    }

    public function test_one_user_receives_calendar_shares_from_two_owners(): void
    {
        // Fails on PostgreSQL with sabre's unique (principaluri, share_href).
        $this->sharing()->share($this->calendar($this->ada), $this->bob, SharingService::READ);
        $this->sharing()->share($this->calendar($this->carol), $this->bob, SharingService::READ_WRITE);

        $received = $this->sharing()->receivedCalendarShares($this->bob);
        $this->assertSame(['default-ada', 'default-carol'], $received->pluck('uri')->all());
        $this->assertSame(['principals/ada', 'principals/carol'], $received->map(fn (CalendarInstance $share): ?string => $share->ownerInstance?->principaluri)->all());

        // Still one share per sharee and calendar.
        $this->sharing()->share($this->calendar($this->ada), $this->bob, SharingService::READ_WRITE);
        $this->assertSame(2, DB::table('calendarinstances')->where('principaluri', 'principals/bob')->where('access', '>', 1)->count());

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('calendarinstances')->insert([
            'calendarid' => $this->calendar($this->ada)->calendarid,
            'principaluri' => 'principals/bob',
            'uri' => 'another-name',
            'access' => SharingService::READ,
            'share_href' => 'principals/bob',
        ]);
    }

    /**
     * A sharee deleted after the panel loaded them gets no share. A share row
     * keyed by their principal would otherwise be inherited by the next user
     * created with that username, and push their own calendar aside.
     */
    public function test_a_deleted_user_cannot_receive_a_share(): void
    {
        $stale = User::query()->findOrFail($this->bob->getKey());
        app(UserService::class)->delete($this->bob);

        $this->assertRefused('That user no longer exists.', fn () => $this->sharing()->share($this->calendar($this->ada), $stale, SharingService::READ_WRITE));
        $this->assertRefused('That user no longer exists.', fn () => $this->sharing()->share($this->book($this->ada), $stale, SharingService::READ_WRITE));
        $this->assertSame(0, DB::table('calendarinstances')->where('principaluri', 'principals/bob')->count());
        $this->assertNoShares();

        $again = $this->user('bob', 'New Bob');
        $this->assertSame(['default'], $again->calendarInstances()->pluck('uri')->all());
    }

    public function test_a_disabled_owner_or_sharee_keeps_the_share(): void
    {
        $this->sharing()->share($this->book($this->ada), $this->bob, SharingService::READ);
        $this->sharing()->share($this->calendar($this->ada), $this->bob, SharingService::READ);

        $this->ada->forceFill(['is_active' => false])->save();
        $this->bob->forceFill(['is_active' => false])->save();

        $this->assertSame(1, AddressBookInstance::query()->count());
        $this->assertSame(1, DB::table('calendarinstances')->where('access', '>', 1)->count());

        // A disabled user can also be given a share.
        $this->assertSame('default-ada', $this->sharing()->share($this->book($this->ada), $this->carol, SharingService::READ)->uri);
    }

    public function test_unshare_removes_the_row_and_the_sharees_locks_and_properties(): void
    {
        $book = $this->book($this->ada);
        $calendar = $this->calendar($this->ada);
        $this->sharing()->share($book, $this->bob, SharingService::READ);
        $this->sharing()->share($calendar, $this->bob, SharingService::READ);
        $this->pathData('addressbooks/bob/default-ada');
        $this->pathData('calendars/bob/default-ada');
        $this->pathData('addressbooks/bob/default');

        $this->assertTrue($this->sharing()->unshare($book, $this->bob));
        $this->assertTrue($this->sharing()->unshare($calendar, $this->bob));

        $this->assertSame(0, AddressBookInstance::query()->count());
        $this->assertSame(0, DB::table('calendarinstances')->where('access', '>', 1)->count());
        $this->assertPathData('addressbooks/bob/default-ada', 0);
        $this->assertPathData('calendars/bob/default-ada', 0);
        $this->assertPathData('addressbooks/bob/default', 1);

        $this->assertFalse($this->sharing()->unshare($book, $this->bob));
        $this->assertFalse($this->sharing()->unshare($calendar, $this->ada), 'The owner\'s row is not a share.');
        $this->assertSame(1, DB::table('calendars')->where('id', $calendar->calendarid)->count());
        $this->assertSame(1, DB::table('addressbooks')->where('id', $book->getKey())->count());
    }

    public function test_sharees_received_shares_and_every_instance_are_listed(): void
    {
        $book = $this->book($this->ada);
        $calendar = $this->calendar($this->ada);
        $this->sharing()->share($book, $this->bob, SharingService::READ);
        $this->sharing()->share($book, $this->carol, SharingService::READ_WRITE);
        $this->sharing()->share($calendar, $this->carol, SharingService::READ);

        $this->assertSame(['principals/bob', 'principals/carol'], $this->sharing()->sharees($book)->pluck('principaluri')->all());
        $this->assertSame(['principals/carol'], $this->sharing()->sharees($calendar)->pluck('principaluri')->all());
        $this->assertSame([], $this->sharing()->sharees($this->book($this->bob))->all());

        $received = $this->sharing()->receivedAddressBookShares($this->carol);
        $this->assertCount(1, $received);
        $this->assertSame($book->getKey(), $received->first()?->addressBook?->getKey());
        $this->assertCount(1, $this->sharing()->receivedCalendarShares($this->carol));
        $this->assertCount(0, $this->sharing()->receivedCalendarShares($this->ada));

        $this->assertSame([
            ['principaluri' => 'principals/ada', 'uri' => 'default', 'access' => 1],
            ['principaluri' => 'principals/bob', 'uri' => 'default-ada', 'access' => 2],
            ['principaluri' => 'principals/carol', 'uri' => 'default-ada', 'access' => 3],
        ], $this->sharing()->instances($book));
        $this->assertSame([
            ['principaluri' => 'principals/ada', 'uri' => 'default', 'access' => 1],
            ['principaluri' => 'principals/carol', 'uri' => 'default-ada', 'access' => 2],
        ], $this->sharing()->instances($calendar));
    }

    public function test_deleting_a_collection_in_the_panel_removes_its_shares(): void
    {
        $book = $this->book($this->ada);
        $calendar = $this->calendar($this->ada);
        $this->sharing()->share($book, $this->bob, SharingService::READ);
        $this->sharing()->share($calendar, $this->bob, SharingService::READ);
        $this->pathData('addressbooks/bob/default-ada');
        $this->pathData('calendars/bob/default-ada');

        app(CollectionService::class)->deleteAddressBook($this->ada, $book);
        app(CollectionService::class)->deleteCalendar($this->ada, $calendar);

        $this->assertNoShares();
        $this->assertPathData('addressbooks/bob/default-ada', 0);
        $this->assertPathData('calendars/bob/default-ada', 0);
    }

    public function test_deleting_a_collection_over_dav_removes_its_shares(): void
    {
        $this->sharing()->share($this->book($this->ada), $this->bob, SharingService::READ_WRITE);
        $this->sharing()->share($this->calendar($this->ada), $this->bob, SharingService::READ_WRITE);
        $this->pathData('addressbooks/bob/default-ada');
        $this->pathData('calendars/bob/default-ada');
        $token = app(AppPasswordService::class)->issue($this->ada, 'phone')['token'];

        $this->dav($token, 'DELETE', '/dav/addressbooks/ada/default/')->assertNoContent();
        $this->dav($token, 'DELETE', '/dav/calendars/ada/default/')->assertNoContent();

        $this->assertNoShares();
        $this->assertPathData('addressbooks/bob/default-ada', 0);
        $this->assertPathData('calendars/bob/default-ada', 0);
    }

    public function test_deleting_a_user_removes_the_shares_they_own_and_received(): void
    {
        $this->sharing()->share($this->book($this->ada), $this->bob, SharingService::READ);
        $this->sharing()->share($this->calendar($this->ada), $this->bob, SharingService::READ);
        $this->sharing()->share($this->book($this->bob), $this->carol, SharingService::READ);
        $this->sharing()->share($this->calendar($this->carol), $this->bob, SharingService::READ);
        $this->pathData('addressbooks/bob/default-ada');
        $this->pathData('calendars/bob/default-ada');
        $this->pathData('addressbooks/carol/default-bob');

        app(UserService::class)->delete($this->ada);

        $this->assertSame(0, DB::table('calendarinstances')->where('principaluri', 'principals/bob')->where('uri', 'default-ada')->count());
        $this->assertSame(0, AddressBookInstance::query()->where('principaluri', 'principals/bob')->count());
        $this->assertPathData('addressbooks/bob/default-ada', 0);
        $this->assertPathData('calendars/bob/default-ada', 0);

        app(UserService::class)->delete($this->bob);

        $this->assertNoShares();
        $this->assertPathData('addressbooks/carol/default-bob', 0);
        $this->assertSame(1, DB::table('calendarinstances')->where('principaluri', 'principals/carol')->count());
    }

    public function test_received_shares_are_not_owned_collections(): void
    {
        $this->sharing()->share($this->calendar($this->ada), $this->bob, SharingService::READ);
        $share = CalendarInstance::query()->where('principaluri', 'principals/bob')->where('access', SharingService::READ)->firstOrFail();

        $this->assertSame(['default'], $this->bob->calendarInstances()->pluck('uri')->all());

        app(ServerCounts::class)->forget();
        $this->assertSame(3, app(ServerCounts::class)->all()['calendars']);

        $this->assertRefused('That collection belongs to another user.', fn () => app(CollectionService::class)->deleteCalendar($this->bob, $share));
        $this->assertRefused('That collection belongs to another user.', fn () => app(CollectionService::class)->updateCalendar($this->bob, $share, 'Mine', null, '#000000', false, false));
        $this->assertSame(1, DB::table('calendarinstances')->where('id', $share->getKey())->count());
    }

    public function test_a_shares_name_is_taken_for_new_collections_of_the_sharee(): void
    {
        $this->sharing()->share($this->book($this->ada), $this->bob, SharingService::READ);
        $collections = app(CollectionService::class);

        $this->assertTrue($collections->addressBookUriTaken($this->bob, 'default-ada'));
        $this->assertRefused('That URI is already used.', fn () => $collections->createAddressBook($this->bob, 'default-ada', 'Mine', null));

        // MKCOL over the share's name.
        $token = app(AppPasswordService::class)->issue($this->bob, 'phone')['token'];
        $this->dav($token, 'MKCOL', '/dav/addressbooks/bob/default-ada/', '<?xml version="1.0"?><d:mkcol xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:carddav"><d:set><d:prop><d:resourcetype><d:collection/><c:addressbook/></d:resourcetype></d:prop></d:set></d:mkcol>')
            ->assertStatus(405);
        $this->assertSame(0, DB::table('addressbooks')->where('principaluri', 'principals/bob')->where('uri', 'default-ada')->count());

        $this->sharing()->share($this->calendar($this->ada), $this->bob, SharingService::READ);
        $this->assertTrue($collections->calendarUriTaken($this->bob, 'default-ada'));
        $this->assertSame('calendar', $collections->suggestedCalendarUri($this->bob));

        $this->subscribe($this->bob, 'feed');
        $this->assertTrue($collections->calendarUriTaken($this->bob, 'feed'));
        $this->assertRefused('That URI is already used.', fn () => $collections->createCalendar($this->bob, 'feed', 'Feed', null, '#000000', false, false));

        $calendars = new CalendarBackend(DB::connection()->getPdo());
        $this->assertDavRefused(fn () => $calendars->createCalendar('principals/bob', 'feed', []));
        // A share that took the name after the caller looked: 405, not a
        // unique-key error from sabre's insert.
        $this->assertDavRefused(fn () => $calendars->createCalendar('principals/bob', 'default-ada', []));
        $this->assertDavRefused(fn () => $calendars->createSubscription('principals/bob', 'default', ['{http://calendarserver.org/ns/}source' => new Href('https://example.com/a.ics')]));
        $books = new AddressBookBackend(DB::connection()->getPdo());
        $this->assertDavRefused(fn () => $books->createAddressBook('principals/bob', 'default-ada', []));
        $this->assertDavRefused(fn () => $books->createAddressBook('principals/bob', 'default', []));
    }

    public function test_the_suggested_address_book_name_skips_a_shares_name(): void
    {
        app(CollectionService::class)->createAddressBook($this->bob, 'contacts', 'Second', null);
        $ownerBook = AddressBook::query()->where('principaluri', 'principals/ada')->where('uri', 'default')->firstOrFail();
        DB::table('addressbooks')->where('id', $ownerBook->getKey())->update(['uri' => 'contacts-2']);
        $ownerBook->refresh();

        $this->sharing()->share($ownerBook, $this->bob, SharingService::READ);

        $this->assertSame('contacts-3', app(CollectionService::class)->suggestedAddressBookUri($this->bob));
    }

    /**
     * Another writer inserts the same name after the free-name read. The
     * unique key stops it; the attempt is rolled back (PostgreSQL aborts the
     * transaction) and the next attempt succeeds.
     */
    public function test_a_unique_key_hit_is_retried_in_a_new_transaction(): void
    {
        app(CollectionService::class)->createAddressBook($this->ada, 'work', 'Work', null);
        $book = AddressBook::query()->where('principaluri', 'principals/ada')->where('uri', 'work')->firstOrFail();
        $other = $this->book($this->carol);
        $fired = false;

        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$fired, $other): void {
            if ($fired || ! str_starts_with(strtolower($query->sql), 'select') || ! str_contains($query->sql, 'addressbookinstances') || ($query->bindings[0] ?? null) !== 'principals/bob') {
                return;
            }

            $fired = true;
            DB::table('addressbookinstances')->insert([
                'addressbookid' => $other->getKey(),
                'principaluri' => 'principals/bob',
                'uri' => 'work',
                'access' => SharingService::READ,
                'share_href' => 'principals/bob',
            ]);
        });

        $share = $this->sharing()->share($book, $this->bob, SharingService::READ);

        $this->assertTrue($fired);
        $this->assertSame('work', $share->uri);
        $this->assertSame([$book->getKey()], AddressBookInstance::query()->pluck('addressbookid')->all(), 'The other writer\'s row was in the rolled-back attempt.');
    }

    public function test_the_free_name_rule(): void
    {
        $this->assertSame('team', SharingService::freeUri('team', 'ada', ['default' => true]));
        $this->assertSame('team-ada', SharingService::freeUri('team', 'ada', ['team' => true]));
        $this->assertSame('team-ada-3', SharingService::freeUri('team', 'ada', ['team' => true, 'team-ada' => true, 'team-ada-2' => true]));
        $this->assertSame('2024-ada', SharingService::freeUri('2024', 'ada', ['2024' => true]));

        $long = str_repeat('b', 200);
        $this->assertSame(str_repeat('b', 194).'-ada-2', SharingService::freeUri($long, 'ada', [$long => true, str_repeat('b', 196).'-ada' => true]));
    }

    private function sharing(): SharingService
    {
        return app(SharingService::class);
    }

    private function user(string $username, string $name): User
    {
        return app(UserService::class)->create(['username' => $username, 'display_name' => $name, 'email' => $username.'@example.com']);
    }

    private function calendar(User $user): CalendarInstance
    {
        return CalendarInstance::query()->where('principaluri', $user->principal_uri)->where('uri', 'default')->where('access', 1)->firstOrFail();
    }

    private function book(User $user): AddressBook
    {
        return AddressBook::query()->where('principaluri', $user->principal_uri)->where('uri', 'default')->firstOrFail();
    }

    private function subscribe(User $user, string $uri): void
    {
        DB::table('calendarsubscriptions')->insert(['principaluri' => $user->principal_uri, 'uri' => $uri, 'source' => 'https://example.com/'.$uri.'.ics']);
    }

    private function pathData(string $path): void
    {
        DB::table('propertystorage')->insert(['path' => $path, 'name' => '{urn:x}note', 'valuetype' => 1, 'value' => 'x']);
        DB::table('locks')->insert(['owner' => 'x', 'timeout' => 60, 'created' => time(), 'token' => 'opaquelocktoken:'.md5($path), 'scope' => 1, 'depth' => 0, 'uri' => $path]);
    }

    private function assertPathData(string $path, int $expected): void
    {
        $this->assertSame($expected, DB::table('propertystorage')->where('path', $path)->count(), 'propertystorage '.$path);
        $this->assertSame($expected, DB::table('locks')->where('uri', $path)->count(), 'locks '.$path);
    }

    private function assertNoShares(): void
    {
        $this->assertSame(0, AddressBookInstance::query()->count());
        $this->assertSame(0, DB::table('calendarinstances')->where('access', '!=', 1)->count());
    }

    private function assertRefused(string $message, callable $call): void
    {
        try {
            $call();
        } catch (InvalidArgumentException $exception) {
            $this->assertSame($message, $exception->getMessage());

            return;
        }

        $this->fail('Expected: '.$message);
    }

    private function assertDavRefused(callable $call): void
    {
        try {
            $call();
        } catch (MethodNotAllowed) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected 405 from the backend.');
    }

    private function dav(string $token, string $method, string $uri, ?string $body = null): TestResponse
    {
        [$username] = explode('/', ltrim(str_replace(['/dav/addressbooks/', '/dav/calendars/'], '', $uri), '/'));

        return $this->call($method, $uri, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Basic '.base64_encode($username.':'.$token),
            'CONTENT_TYPE' => 'application/xml',
        ], $body);
    }
}
