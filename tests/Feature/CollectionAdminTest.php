<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\RelationManagers\AddressBooksRelationManager;
use App\Filament\Resources\Users\RelationManagers\CalendarsRelationManager;
use App\Models\AddressBook;
use App\Models\CalendarInstance;
use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\Dav\CalendarBackend;
use App\Services\Dav\CollectionService;
use App\Services\Dav\SyncToken;
use App\Services\UserService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Livewire\Livewire;
use PDO;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class CollectionAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_new_users_get_one_default_calendar_and_address_book(): void
    {
        $user = app(UserService::class)->create([
            'username' => 'ada',
            'display_name' => 'Ada',
            'email' => 'ada@example.com',
        ]);

        $this->assertSame('Calendar', DB::table('calendarinstances')->where('principaluri', 'principals/ada')->value('displayname'));
        $this->assertSame('#1A73E8', DB::table('calendarinstances')->where('principaluri', 'principals/ada')->value('calendarcolor'));
        $this->assertSame('VEVENT,VTODO,VJOURNAL', DB::table('calendars')->value('components'));
        $this->assertSame('Contacts', DB::table('addressbooks')->where('principaluri', 'principals/ada')->value('displayname'));
        $this->assertSame('default', DB::table('addressbooks')->where('principaluri', 'principals/ada')->value('uri'));

        $calendarId = DB::table('calendars')->value('id');
        $bookId = DB::table('addressbooks')->value('id');
        app(UserService::class)->backfillHomeCollections();

        $this->assertSame(1, DB::table('calendars')->count());
        $this->assertSame(1, DB::table('addressbooks')->count());
        $this->assertSame($calendarId, DB::table('calendars')->value('id'));
        $this->assertSame($bookId, DB::table('addressbooks')->value('id'));
        $this->assertNotNull($user->fresh());
    }

    public function test_admin_can_create_edit_and_delete_a_calendar_visible_over_dav(): void
    {
        $admin = $this->admin();
        $ada = $this->user('ada_l', 'Ada Lovelace', 'ada@example.com');
        $bob = $this->user('adaxl', 'Neighbour', 'bob@example.com');
        $adaToken = $this->token($ada);
        $bobToken = $this->token($bob);
        $secret = 'Ada Secret Meeting';

        $this->actingAs($admin);

        Livewire::test(CalendarsRelationManager::class, [
            'ownerRecord' => $ada,
            'pageClass' => EditUser::class,
        ])
            ->assertSee('/dav/calendars/ada_l/default/')
            ->assertDontSee('TASKS')
            ->callTableAction('create', data: [
                'uri' => '2024',
                'display_name' => 'Year',
                'description' => 'Notes for the year',
                'color' => '#ab12cd',
                'tasks' => true,
                'notes' => false,
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Calendar created');

        $calendar = CalendarInstance::query()
            ->where('principaluri', 'principals/ada_l')
            ->where('uri', '2024')
            ->first();
        $this->assertNotNull($calendar);
        $this->assertSame('Year', $calendar->displayname);
        $this->assertSame('#AB12CD', $calendar->calendarcolor);
        $this->assertSame('Notes for the year', $calendar->description);
        $this->assertSame('VEVENT,VTODO', DB::table('calendars')->where('id', $calendar->calendarid)->value('components'));

        Livewire::test(CalendarsRelationManager::class, [
            'ownerRecord' => $ada,
            'pageClass' => EditUser::class,
        ])->assertSee('/dav/calendars/ada_l/2024/');

        $listed = $this->dav('PROPFIND', '/dav/calendars/ada_l', 'ada_l', $adaToken, ['Depth' => '1'], $this->propfind());
        $listed->assertStatus(207);
        $this->assertStringContainsString('/dav/calendars/ada_l/2024/', $this->body($listed));

        $props = $this->dav('PROPFIND', '/dav/calendars/ada_l/2024', 'ada_l', $adaToken, ['Depth' => '0'], $this->propfind());
        $props->assertStatus(207);
        $this->assertStringContainsString('Year', $this->body($props));

        $put = $this->dav('PUT', '/dav/calendars/ada_l/2024/planning.ics', 'ada_l', $adaToken, [
            'Content-Type' => 'text/calendar',
        ], $this->event($secret));
        $this->assertContains($put->getStatusCode(), [201, 204], $this->body($put));

        $got = $this->dav('GET', '/dav/calendars/ada_l/2024/planning.ics', 'ada_l', $adaToken);
        $got->assertOk();
        $this->assertStringContainsString($secret, $this->body($got));

        Livewire::test(CalendarsRelationManager::class, [
            'ownerRecord' => $ada,
            'pageClass' => EditUser::class,
        ])
            ->assertTableColumnStateSet('events_count', '1', $calendar)
            ->assertTableColumnStateSet('tasks_count', '0', $calendar)
            ->assertTableColumnStateSet('notes_count', '—', $calendar);

        $this->assertDenied($this->dav('PROPFIND', '/dav/calendars/ada_l/2024', 'adaxl', $bobToken, ['Depth' => '1'], $this->propfind()), $secret);
        $this->assertDenied($this->dav('GET', '/dav/calendars/ada_l/2024/planning.ics', 'adaxl', $bobToken), $secret);
        $this->assertDenied($this->dav('PUT', '/dav/calendars/ada_l/2024/evil.ics', 'adaxl', $bobToken, [
            'Content-Type' => 'text/calendar',
        ], $this->event($secret)), $secret);

        Livewire::test(CalendarsRelationManager::class, [
            'ownerRecord' => $ada,
            'pageClass' => EditUser::class,
        ])
            ->callTableAction('edit', $calendar, data: [
                'display_name' => 'Archive',
                'description' => '',
                'color' => '#001122',
                'tasks' => false,
                'notes' => true,
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Calendar saved');

        $calendar->refresh();
        $this->assertSame('2024', $calendar->uri);
        $this->assertSame('Archive', $calendar->displayname);
        $this->assertNull($calendar->description);
        $this->assertSame('#001122', $calendar->calendarcolor);
        $this->assertSame('VEVENT,VJOURNAL', DB::table('calendars')->where('id', $calendar->calendarid)->value('components'));

        $renamed = $this->dav('PROPFIND', '/dav/calendars/ada_l/2024', 'ada_l', $adaToken, ['Depth' => '0'], $this->propfind());
        $renamed->assertStatus(207);
        $this->assertStringContainsString('Archive', $this->body($renamed));

        Livewire::test(CalendarsRelationManager::class, [
            'ownerRecord' => $ada,
            'pageClass' => EditUser::class,
        ])
            ->assertTableColumnStateSet('events_count', '1', $calendar)
            ->assertTableColumnStateSet('tasks_count', '—', $calendar)
            ->assertTableColumnStateSet('notes_count', '0', $calendar);

        $this->insertProperty('calendars/ada_l/2024', 'Ada calendar');
        $this->insertProperty('calendars/ada_l/2024/planning.ics', 'Ada event');
        $this->insertProperty('calendars/adaxl/2024', 'Neighbour');
        $this->insertLock('calendars/ada_l/2024', 'opaquelocktoken:ada');
        $this->insertLock('calendars/adaxl/default', 'opaquelocktoken:bob');

        Livewire::test(CalendarsRelationManager::class, [
            'ownerRecord' => $ada,
            'pageClass' => EditUser::class,
        ])
            ->callTableAction('delete', $calendar)
            ->assertNotified('Calendar deleted');

        $this->assertNull($calendar->fresh());
        $this->assertSame(0, DB::table('calendarobjects')->where('calendarid', $calendar->calendarid)->count());
        $this->assertSame(0, DB::table('calendarchanges')->where('calendarid', $calendar->calendarid)->count());
        $this->assertSame(0, DB::table('calendars')->where('id', $calendar->calendarid)->count());
        $this->assertSame(0, DB::table('propertystorage')->where('path', 'calendars/ada_l/2024')->count());
        $this->assertSame(0, DB::table('propertystorage')->where('path', 'calendars/ada_l/2024/planning.ics')->count());
        $this->assertSame(0, DB::table('locks')->where('token', 'opaquelocktoken:ada')->count());
        $this->assertSame(1, DB::table('propertystorage')->where('path', 'calendars/adaxl/2024')->count());
        $this->assertSame(1, DB::table('locks')->where('token', 'opaquelocktoken:bob')->count());
        $this->assertSame(1, DB::table('calendarinstances')->where('principaluri', 'principals/adaxl')->where('uri', 'default')->count());

        $gone = $this->dav('PROPFIND', '/dav/calendars/ada_l/2024', 'ada_l', $adaToken, ['Depth' => '0'], $this->propfind());
        $this->assertContains($gone->getStatusCode(), [404], $this->body($gone));
        $this->dav('GET', '/dav/calendars/ada_l/2024/planning.ics', 'ada_l', $adaToken)->assertNotFound();
        $this->assertNotNull($bob->fresh());
    }

    public function test_admin_can_create_edit_and_delete_an_address_book_visible_over_dav(): void
    {
        $admin = $this->admin();
        $ada = $this->user('ada', 'Ada', 'ada@example.com');
        $bob = $this->user('bob', 'Bob', 'bob@example.com');
        $adaToken = $this->token($ada);
        $bobToken = $this->token($bob);
        $secret = 'Ada Private Contact';

        $this->actingAs($admin);

        Livewire::test(AddressBooksRelationManager::class, [
            'ownerRecord' => $ada,
            'pageClass' => EditUser::class,
        ])
            ->assertSee('Contacts')
            ->callTableAction('create', data: [
                'uri' => 'work',
                'display_name' => 'Work',
                'description' => 'Office',
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Address book created');

        $book = AddressBook::query()->where('principaluri', 'principals/ada')->where('uri', 'work')->first();
        $this->assertNotNull($book);
        $this->assertSame('Office', $book->description);

        $listed = $this->dav('PROPFIND', '/dav/addressbooks/ada', 'ada', $adaToken, ['Depth' => '1'], $this->propfind());
        $listed->assertStatus(207);
        $this->assertStringContainsString('/dav/addressbooks/ada/work/', $this->body($listed));

        $put = $this->dav('PUT', '/dav/addressbooks/ada/work/ada.vcf', 'ada', $adaToken, [
            'Content-Type' => 'text/vcard',
        ], $this->card($secret));
        $this->assertContains($put->getStatusCode(), [201, 204], $this->body($put));

        $got = $this->dav('GET', '/dav/addressbooks/ada/work/ada.vcf', 'ada', $adaToken);
        $got->assertOk();
        $this->assertStringContainsString($secret, $this->body($got));

        $this->assertDenied($this->dav('PROPFIND', '/dav/addressbooks/ada/work', 'bob', $bobToken, ['Depth' => '1'], $this->propfind()), $secret);
        $this->assertDenied($this->dav('GET', '/dav/addressbooks/ada/work/ada.vcf', 'bob', $bobToken), $secret);
        $this->assertDenied($this->dav('PUT', '/dav/addressbooks/ada/work/evil.vcf', 'bob', $bobToken, [
            'Content-Type' => 'text/vcard',
        ], $this->card($secret)), $secret);

        Livewire::test(AddressBooksRelationManager::class, [
            'ownerRecord' => $ada,
            'pageClass' => EditUser::class,
        ])
            ->callTableAction('edit', $book, data: [
                'display_name' => 'Clients',
                'description' => '',
            ])
            ->assertNotified('Address book saved');

        $book->refresh();
        $this->assertSame('work', $book->uri);
        $this->assertSame('Clients', $book->displayname);
        $this->assertNull($book->description);

        $renamed = $this->dav('PROPFIND', '/dav/addressbooks/ada/work', 'ada', $adaToken, ['Depth' => '0'], $this->propfind());
        $renamed->assertStatus(207);
        $this->assertStringContainsString('Clients', $this->body($renamed));

        $this->insertProperty('addressbooks/ada/work', 'Ada book');
        $this->insertProperty('addressbooks/ada/work/ada.vcf', 'Ada card');
        $this->insertProperty('addressbooks/bob/work', 'Bob book');

        Livewire::test(AddressBooksRelationManager::class, [
            'ownerRecord' => $ada,
            'pageClass' => EditUser::class,
        ])
            ->callTableAction('delete', $book)
            ->assertNotified('Address book deleted');

        $this->assertSame(0, DB::table('cards')->where('addressbookid', $book->getKey())->count());
        $this->assertSame(0, DB::table('addressbookchanges')->where('addressbookid', $book->getKey())->count());
        $this->assertSame(0, DB::table('addressbooks')->where('id', $book->getKey())->count());
        $this->assertSame(0, DB::table('propertystorage')->where('path', 'addressbooks/ada/work')->count());
        $this->assertSame(0, DB::table('propertystorage')->where('path', 'addressbooks/ada/work/ada.vcf')->count());
        $this->assertSame(1, DB::table('propertystorage')->where('path', 'addressbooks/bob/work')->count());
        $this->assertSame(1, DB::table('addressbooks')->where('principaluri', 'principals/bob')->where('uri', 'default')->count());

        $gone = $this->dav('GET', '/dav/addressbooks/ada/work/ada.vcf', 'ada', $adaToken);
        $gone->assertNotFound();
    }

    public function test_calendar_slug_and_color_are_validated(): void
    {
        $admin = $this->admin();
        $ada = $this->user('ada', 'Ada', 'ada@example.com');
        $before = DB::table('calendarinstances')->where('principaluri', 'principals/ada')->count();

        $this->actingAs($admin);

        foreach (['inbox', 'outbox', 'notifications', 'Bad Slug', 'a/b', '..'] as $uri) {
            Livewire::test(CalendarsRelationManager::class, [
                'ownerRecord' => $ada,
                'pageClass' => EditUser::class,
            ])
                ->callTableAction('create', data: [
                    'uri' => $uri,
                    'display_name' => 'Nope',
                    'color' => '#112233',
                ])
                ->assertHasFormErrors(['uri']);
        }

        foreach (['#abc', '#11223344', 'blue', '112233'] as $color) {
            Livewire::test(CalendarsRelationManager::class, [
                'ownerRecord' => $ada,
                'pageClass' => EditUser::class,
            ])
                ->callTableAction('create', data: [
                    'uri' => 'ok-color',
                    'display_name' => 'Nope',
                    'color' => $color,
                ])
                ->assertHasFormErrors(['color']);
        }

        Livewire::test(CalendarsRelationManager::class, [
            'ownerRecord' => $ada,
            'pageClass' => EditUser::class,
        ])
            ->callTableAction('create', data: [
                'uri' => 'default',
                'display_name' => 'Again',
                'color' => '#112233',
            ])
            ->assertHasFormErrors(['uri']);

        $this->assertSame($before, DB::table('calendarinstances')->where('principaluri', 'principals/ada')->count());

        try {
            app(CollectionService::class)->createCalendar($ada, 'inbox', 'Inbox', null, '#112233', true, true);
            $this->fail('A reserved calendar URI was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('This name is reserved.', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('calendarinstances')->where('uri', 'inbox')->count());
    }

    public function test_disabled_components_are_left_out_of_new_calendars_and_kept_on_edit(): void
    {
        $admin = $this->admin();
        $ada = $this->user('ada', 'Ada', 'ada@example.com');
        $existing = CalendarInstance::query()->where('principaluri', 'principals/ada')->where('uri', 'default')->first();
        $this->assertNotNull($existing);
        $this->assertSame('VEVENT,VTODO,VJOURNAL', DB::table('calendars')->where('id', $existing->calendarid)->value('components'));

        config([
            'nexusdav.features.tasks' => false,
            'nexusdav.features.notes' => false,
        ]);

        $this->actingAs($admin);

        Livewire::test(CalendarsRelationManager::class, [
            'ownerRecord' => $ada,
            'pageClass' => EditUser::class,
        ])
            ->callTableAction('create', data: [
                'uri' => 'events',
                'display_name' => 'Events',
                'color' => '#112233',
                'tasks' => true,
                'notes' => true,
            ])
            ->assertNotified('Calendar created');

        $createdId = DB::table('calendarinstances')->where('uri', 'events')->value('calendarid');
        $this->assertSame('VEVENT', DB::table('calendars')->where('id', $createdId)->value('components'));

        Livewire::test(CalendarsRelationManager::class, [
            'ownerRecord' => $ada,
            'pageClass' => EditUser::class,
        ])
            ->callTableAction('edit', $existing, data: [
                'display_name' => 'Still mixed',
                'description' => '',
                'color' => '#112233',
                'tasks' => false,
                'notes' => false,
            ])
            ->assertNotified('Calendar saved');

        $this->assertSame('VEVENT,VTODO,VJOURNAL', DB::table('calendars')->where('id', $existing->calendarid)->value('components'));
        $this->assertSame('Still mixed', $existing->fresh()?->displayname);
    }

    public function test_backfill_does_not_restore_a_deleted_default_collection(): void
    {
        $ada = $this->user('ada', 'Ada', 'ada@example.com');
        $calendar = CalendarInstance::query()->where('principaluri', 'principals/ada')->where('uri', 'default')->first();
        $book = AddressBook::query()->where('principaluri', 'principals/ada')->where('uri', 'default')->first();
        $this->assertNotNull($calendar);
        $this->assertNotNull($book);
        $bookId = $book->getKey();

        app(CollectionService::class)->deleteCalendar($ada, $calendar);
        app(UserService::class)->backfillHomeCollections();
        app(UserService::class)->backfillHomeCollections();

        $this->assertSame(0, DB::table('calendarinstances')->where('principaluri', 'principals/ada')->count());
        $this->assertSame($bookId, DB::table('addressbooks')->where('principaluri', 'principals/ada')->value('id'));

        app(CollectionService::class)->deleteAddressBook($ada, $book->fresh() ?? $book);
        app(UserService::class)->backfillHomeCollections();

        $this->assertSame(0, DB::table('addressbooks')->where('principaluri', 'principals/ada')->count());
        $this->assertSame(0, DB::table('calendars')->count());

        $plain = User::withoutEvents(function (): User {
            return User::query()->create([
                'username' => 'bob',
                'display_name' => 'Bob',
                'email' => 'bob@example.com',
                'password' => null,
                'is_admin' => false,
                'is_active' => true,
            ]);
        });

        app(UserService::class)->backfillHomeCollections();
        app(UserService::class)->backfillHomeCollections();

        $this->assertSame(1, DB::table('calendarinstances')->where('principaluri', 'principals/bob')->where('uri', 'default')->count());
        $this->assertSame(1, DB::table('addressbooks')->where('principaluri', 'principals/bob')->where('uri', 'default')->count());
        $this->assertSame(0, DB::table('calendarinstances')->where('principaluri', 'principals/ada')->count());
        $this->assertNotNull($plain->fresh()?->principal);
    }

    public function test_component_edit_keeps_the_sync_token_with_the_change_log(): void
    {
        $admin = $this->admin();
        $ada = $this->user('ada', 'Ada', 'ada@example.com');
        $calendar = CalendarInstance::query()->where('principaluri', 'principals/ada')->where('uri', 'default')->first();
        $this->assertNotNull($calendar);

        $this->actingAs($admin);

        Livewire::test(CalendarsRelationManager::class, [
            'ownerRecord' => $ada,
            'pageClass' => EditUser::class,
        ])
            ->callTableAction('edit', $calendar, data: [
                'display_name' => 'Calendar',
                'description' => '',
                'color' => '#1A73E8',
                'tasks' => false,
                'notes' => true,
            ])
            ->assertNotified('Calendar saved');

        $calendarId = (int) $calendar->calendarid;
        $token = (int) DB::table('calendars')->where('id', $calendarId)->value('synctoken');
        $maxChange = (int) DB::table('calendarchanges')->where('calendarid', $calendarId)->max('synctoken');
        $this->assertSame($maxChange + 1, $token);
        $this->assertSame('VEVENT,VJOURNAL', DB::table('calendars')->where('id', $calendarId)->value('components'));

        $backend = new CalendarBackend(DB::connection()->getPdo());
        $id = [$calendarId, (int) $calendar->getKey()];
        $clientToken = SyncToken::format($calendarId, $token);
        $quiet = $backend->getChangesForCalendar($id, $clientToken, 1);
        $this->assertIsArray($quiet);
        $this->assertSame([], $quiet['added']);
        $this->assertSame($clientToken, $quiet['syncToken']);

        $backend->createCalendarObject($id, 'later.ics', $this->event('Later'));
        $next = $backend->getChangesForCalendar($id, $clientToken, 1);
        $this->assertIsArray($next);
        $this->assertContains('later.ics', $next['added']);
        $this->assertSame(
            SyncToken::format($calendarId, (int) DB::table('calendars')->where('id', $calendarId)->value('synctoken')),
            $next['syncToken'],
        );
    }

    public function test_suggested_uri_is_the_first_free_slug(): void
    {
        $ada = $this->user('ada', 'Ada', 'ada@example.com');
        $collections = app(CollectionService::class);

        $this->assertSame('calendar', $collections->suggestedCalendarUri($ada));
        $this->assertSame('contacts', $collections->suggestedAddressBookUri($ada));

        $collections->createCalendar($ada, 'calendar', 'Extra', null, '#112233', false, false);
        $collections->createAddressBook($ada, 'contacts', 'Extra', null);

        $this->assertSame('calendar-2', $collections->suggestedCalendarUri($ada));
        $this->assertSame('contacts-2', $collections->suggestedAddressBookUri($ada));
    }

    public function test_edit_page_shows_the_principal_and_collection_counts(): void
    {
        $admin = $this->admin();
        $ada = $this->user('ada', 'Ada', 'ada@example.com');

        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $ada->getKey()])
            ->assertSee('User Information')
            ->assertSee('principals/ada')
            ->assertSee('Calendars')
            ->assertSee('Events/Objects')
            ->assertSee('Address Books')
            ->assertSee('Contacts');
    }

    private function admin(): User
    {
        return User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
    }

    private function user(string $username, string $displayName, string $email): User
    {
        return app(UserService::class)->create([
            'username' => $username,
            'display_name' => $displayName,
            'email' => $email,
        ]);
    }

    private function token(User $user): string
    {
        return app(AppPasswordService::class)->issue($user, 'phone')['token'];
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function dav(string $method, string $uri, ?string $username = null, ?string $password = null, array $headers = [], ?string $body = null): TestResponse
    {
        $server = [];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        if ($username !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Basic '.base64_encode($username.':'.(string) $password);
        }

        return $this->call($method, $uri, [], [], [], $server, $body);
    }

    private function body(TestResponse $response): string
    {
        if ($response->baseResponse instanceof StreamedResponse) {
            return $response->streamedContent();
        }

        return (string) $response->getContent();
    }

    private function assertDenied(TestResponse $response, string $secret): void
    {
        $body = $this->body($response);

        $this->assertContains($response->getStatusCode(), [403, 404], $body);
        $this->assertStringNotContainsString($secret, $body);
    }

    private function propfind(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8" ?>
<D:propfind xmlns:D="DAV:">
  <D:prop>
    <D:displayname/>
    <D:resourcetype/>
  </D:prop>
</D:propfind>
XML;
    }

    public function test_the_calendars_table_counts_events_tasks_and_notes_separately(): void
    {
        $ada = $this->user('ada', 'Ada', 'ada@example.com');
        $token = $this->token($ada);
        $this->actingAs($this->admin());
        $calendar = CalendarInstance::query()->where('principaluri', 'principals/ada')->where('uri', 'default')->sole();

        $items = ['e1' => 'VEVENT', 'e2' => 'VEVENT', 't1' => 'VTODO', 'n1' => 'VJOURNAL'];

        foreach ($items as $uid => $component) {
            $body = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//NexusDAV//EN\r\nBEGIN:$component\r\nUID:$uid\r\nDTSTAMP:20261028T120000Z\r\n"
                .($component === 'VEVENT' ? "DTSTART:20261028T130000Z\r\n" : '')
                ."SUMMARY:$uid\r\nEND:$component\r\nEND:VCALENDAR\r\n";
            $this->dav('PUT', '/dav/calendars/ada/default/'.$uid.'.ics', 'ada', $token, ['Content-Type' => 'text/calendar'], $body)->assertCreated();
        }

        $table = fn () => Livewire::test(CalendarsRelationManager::class, ['ownerRecord' => $ada, 'pageClass' => EditUser::class]);

        $table()
            ->assertTableColumnStateSet('events_count', '2', $calendar)
            ->assertTableColumnStateSet('tasks_count', '1', $calendar)
            ->assertTableColumnStateSet('notes_count', '1', $calendar);

        // The calendar stops accepting tasks; the stored task is still counted.
        app(CollectionService::class)->updateCalendar($ada, $calendar, 'Calendar', null, '#1A73E8', false, true);
        $table()->assertTableColumnStateSet('tasks_count', '1', $calendar);

        // With the Tasks switch off on the Settings page, the column is hidden.
        config(['nexusdav.features.tasks' => false]);
        $table()
            ->assertTableColumnHidden('tasks_count')
            ->assertTableColumnVisible('notes_count');
    }

    private function event(string $summary): string
    {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//NexusDAV//EN',
            'BEGIN:VEVENT',
            'UID:planning@nexusdav.test',
            'DTSTAMP:20261028T120000Z',
            'DTSTART:20261028T130000Z',
            'DTEND:20261028T140000Z',
            'SUMMARY:'.$summary,
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);
    }

    private function card(string $name): string
    {
        return implode("\r\n", [
            'BEGIN:VCARD',
            'VERSION:3.0',
            'UID:ada-contact@nexusdav.test',
            'FN:'.$name,
            'END:VCARD',
            '',
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

    private function insertLock(string $uri, string $token): void
    {
        DB::table('locks')->insert([
            'owner' => 'ada',
            'timeout' => 60,
            'created' => time(),
            'token' => $token,
            'scope' => 0,
            'depth' => 1,
            'uri' => $uri,
        ]);
    }
}
