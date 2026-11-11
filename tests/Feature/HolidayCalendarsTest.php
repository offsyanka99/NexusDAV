<?php

namespace Tests\Feature;

use App\Models\CalendarInstance;
use App\Models\HolidayCalendar;
use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\Dav\SharingService;
use App\Services\Holidays\HolidayCalendarService;
use App\Services\Holidays\HolidayCatalog;
use App\Services\Holidays\HolidayEvents;
use App\Services\UserService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;
use Yasumi\Yasumi;

/**
 * PR 41 of the Version 1.x.x plan: holiday calendars that the server fills
 * from Yasumi and that clients can read but not change.
 */
class HolidayCalendarsTest extends TestCase
{
    use RefreshDatabase;

    private User $ada;

    private string $adaToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 11, 10)->setTime(12, 0));
        $this->ada = $this->user('ada', 'Ada Lovelace');
        $this->adaToken = $this->token($this->ada);
    }

    public function test_a_holiday_calendar_holds_the_regions_holidays_for_the_range(): void
    {
        $holiday = $this->create('CA-ON', 'holidays-ca-on');

        $this->assertSame(2025, $holiday->from_year);
        $this->assertSame(2029, $holiday->to_year);
        $this->assertSame('en_CA', $holiday->locale);
        $this->assertSame(['official'], $holiday->types);
        $this->assertSame(HolidayEvents::dataVersion(), $holiday->data_version);
        $this->assertSame(65, $this->objects($holiday)->count());
        $this->assertSame('VEVENT', DB::table('calendars')->where('id', $holiday->calendar_id)->value('components'));

        for ($year = 2025; $year <= 2029; $year++) {
            $this->assertTrue($this->objects($holiday)->contains('uri', HolidayEvents::uid($holiday->calendar_id, $year, 'canadaDay').'.ics'));
        }

        $event = $this->body($this->dav('GET', $this->url('holidays-ca-on').HolidayEvents::uid($holiday->calendar_id, 2027, 'canadaDay').'.ics')->assertOk());
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20270701', $event);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20270702', $event);
        $this->assertStringContainsString('SUMMARY:Canada Day', $event);
        $this->assertStringContainsString('DESCRIPTION:Official holiday', $event);
        $this->assertStringContainsString('TRANSP:TRANSPARENT', $event);
        $this->assertStringContainsString('CLASS:PUBLIC', $event);
        $this->assertStringContainsString('PRODID:-//NexusDAV//Holidays//EN', $event);
        $this->assertStringNotContainsString('VALARM', $event);

        $ukraine = $this->create('UA', 'holidays-ua');
        $this->assertSame('uk_UA', $ukraine->locale);
        $independence = $this->body($this->dav('GET', $this->url('holidays-ua').HolidayEvents::uid($ukraine->calendar_id, 2026, 'independenceDay').'.ics')->assertOk());
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20260824', $independence);
        $this->assertStringContainsString('SUMMARY:День Незалежності', $independence);
        $this->assertStringContainsString('DESCRIPTION:Державне свято', $independence);

        $expected = 0;

        for ($year = 2025; $year <= 2029; $year++) {
            $expected += iterator_count(Yasumi::create('Ukraine', $year)->getIterator());
        }

        $this->assertSame($expected, $this->objects($ukraine)->count());
    }

    public function test_names_follow_the_language_and_the_events_keep_their_uids(): void
    {
        $holiday = $this->create('UA', 'holidays-ua', 'en_US');
        $uri = HolidayEvents::uid($holiday->calendar_id, 2026, 'independenceDay').'.ics';
        $substitute = HolidayEvents::uid($holiday->calendar_id, 2026, 'substituteHoliday:internationalWomensDay').'.ics';
        $uris = $this->objects($holiday)->pluck('uri')->sort()->values()->all();

        $this->assertSame('holiday-'.$holiday->calendar_id.'-2026-substituteholiday-internationalwomensday@nexusdav.ics', $substitute);
        $this->assertStringContainsString('SUMMARY:Independence Day', $this->event($holiday, $uri));
        $this->assertStringContainsString('SUMMARY:International Women’s Day observed', $this->event($holiday, $substitute));

        $token = $this->syncTokenOf($this->url('holidays-ua'));
        $changes = app(HolidayCalendarService::class)->update($holiday, 'UA', 'ru_RU', ['official']);

        $this->assertSame(0, $changes->added);
        $this->assertSame(0, $changes->removed);
        $this->assertSame(count($uris), $changes->changed);
        $this->assertSame($uris, $this->objects($holiday)->pluck('uri')->sort()->values()->all());
        $this->assertStringContainsString('SUMMARY:День Независимости', $this->event($holiday, $uri));
        $this->assertStringContainsString('SUMMARY:Международный женский день (перенесённый выходной)', $this->event($holiday, $substitute));
        $this->assertStringContainsString('DESCRIPTION:Государственный праздник', $this->event($holiday, $uri));
        $this->assertCount(count($uris), $this->hrefs($this->sync($this->url('holidays-ua'), $token)));

        app(HolidayCalendarService::class)->update($holiday, 'UA', 'uk_UA', ['official']);
        $this->assertStringContainsString('SUMMARY:День Незалежності', $this->event($holiday, $uri));
        $this->assertStringContainsString('SUMMARY:Міжнародний жіночий день (перенесений вихідний)', $this->event($holiday, $substitute));

        $this->dav('GET', $this->url('holidays-ua').$substitute)->assertOk();
    }

    public function test_a_refresh_without_a_difference_writes_nothing(): void
    {
        $holiday = $this->create('CA-ON', 'holidays-ca-on');
        $before = $this->tokenState($holiday);
        $token = $this->syncTokenOf($this->url('holidays-ca-on'));

        $this->travel(1)->days();
        $changes = app(HolidayCalendarService::class)->refresh($holiday);

        $this->assertTrue($changes->none());
        $this->assertSame($before, $this->tokenState($holiday));
        $this->assertSame($token, $this->syncTokenOf($this->url('holidays-ca-on')));
        $this->assertSame([], $this->hrefs($this->sync($this->url('holidays-ca-on'), $token)));
        $this->assertSame(now()->toDateString(), $holiday->fresh()?->refreshed_at?->toDateString());
    }

    public function test_a_new_year_extends_the_range_and_keeps_the_past(): void
    {
        $holiday = $this->create('CA-ON', 'holidays-ca-on');

        $this->travelTo(now()->setDate(2027, 1, 2));
        $changes = app(HolidayCalendarService::class)->refresh($holiday);

        $this->assertSame(13, $changes->added);
        $this->assertSame(0, $changes->changed);
        $this->assertSame(0, $changes->removed);
        $this->assertSame(2025, $holiday->fresh()?->from_year);
        $this->assertSame(2030, $holiday->fresh()?->to_year);
        $this->assertSame(78, $this->objects($holiday)->count());
    }

    public function test_a_changed_region_writes_only_the_differences(): void
    {
        $holiday = $this->create('CA-ON', 'holidays-ca-on');
        $token = $this->syncTokenOf($this->url('holidays-ca-on'));

        $changes = app(HolidayCalendarService::class)->update($holiday, 'CA-QC', 'en_CA', ['official']);

        // Family Day and Civic Holiday go; National Patriots' Day and Saint-Jean-Baptiste come, each year.
        $this->assertSame(10, $changes->added);
        $this->assertSame(10, $changes->removed);
        $this->assertTrue($this->objects($holiday)->contains('uri', HolidayEvents::uid($holiday->calendar_id, 2027, 'saintJeanBaptisteDay').'.ics'));
        $this->assertFalse($this->objects($holiday)->contains('uri', HolidayEvents::uid($holiday->calendar_id, 2027, 'familyDay').'.ics'));

        $hrefs = $this->hrefs($this->sync($this->url('holidays-ca-on'), $token));
        $this->assertCount($changes->added + $changes->changed + $changes->removed, $hrefs);
        $this->assertLessThan(65, count($hrefs));
        $this->assertSame('CA-QC', $holiday->fresh()?->region);
    }

    public function test_more_types_add_their_holidays(): void
    {
        $holiday = $this->create('UA', 'holidays-ua');
        $official = $this->objects($holiday)->count();

        $changes = app(HolidayCalendarService::class)->update($holiday, 'UA', 'uk_UA', ['official', 'observance', 'other']);

        $this->assertSame($official + $changes->added, $this->objects($holiday)->count());
        $this->assertSame(0, $changes->removed);
        $this->assertSame(['official', 'observance', 'other'], $holiday->fresh()?->types);
    }

    public function test_a_failure_halfway_leaves_the_calendar_as_it_was(): void
    {
        $holiday = $this->create('CA-ON', 'holidays-ca-on');
        $before = $this->tokenState($holiday);
        $uris = $this->objects($holiday)->pluck('uri')->sort()->values()->all();

        // The events are written first; the row is saved last, in the same transaction.
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'update "holiday_calendars"')) {
                throw new RuntimeException('Disk full');
            }
        });

        try {
            app(HolidayCalendarService::class)->update($holiday, 'CA-QC', 'fr_CA', ['official']);
            $this->fail('The update should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Disk full', $exception->getMessage());
        }

        $this->assertSame($before, $this->tokenState($holiday));
        $this->assertSame($uris, $this->objects($holiday)->pluck('uri')->sort()->values()->all());
        $this->assertSame('CA-ON', HolidayCalendar::query()->find($holiday->getKey())?->region);
    }

    public function test_clients_can_read_but_not_change_the_events(): void
    {
        $holiday = $this->create('CA-ON', 'holidays-ca-on');
        $url = $this->url('holidays-ca-on');
        $event = HolidayEvents::uid($holiday->calendar_id, 2027, 'canadaDay').'.ics';
        $this->dav('PUT', '/dav/calendars/ada/default/mine.ics', [], $this->item('mine'))->assertCreated();
        $before = $this->tokenState($holiday);

        $collection = $this->privileges($this->body($this->dav('PROPFIND', $url, ['Depth' => '0'], $this->propfind('<d:current-user-privilege-set/>'))->assertStatus(207)));
        $this->assertContains('read', $collection);
        $this->assertContains('write-properties', $collection);
        foreach (['write', 'write-content', 'bind', 'unbind'] as $privilege) {
            $this->assertNotContains($privilege, $collection);
        }

        $item = $this->privileges($this->body($this->dav('PROPFIND', $url.$event, ['Depth' => '0'], $this->propfind('<d:current-user-privilege-set/>'))->assertStatus(207)));
        $this->assertContains('read', $item);
        foreach (['write', 'write-properties', 'write-content'] as $privilege) {
            $this->assertNotContains($privilege, $item);
        }

        $etag = (string) $this->dav('GET', $url.$event)->assertOk()->headers->get('ETag');
        $this->dav('PUT', $url.'new.ics', ['Content-Type' => 'text/calendar'], $this->item('new'))->assertForbidden();
        $this->dav('PUT', $url.$event, ['Content-Type' => 'text/calendar', 'If-Match' => $etag], $this->item('changed'))->assertForbidden();
        $this->dav('DELETE', $url.$event)->assertForbidden();
        $this->dav('PROPPATCH', $url.$event, ['Content-Type' => 'application/xml'], $this->deadProperty())->assertForbidden();
        $this->dav('MOVE', $url.$event, ['Destination' => 'http://localhost/dav/calendars/ada/default/moved.ics', 'Overwrite' => 'F'])->assertForbidden();
        $this->dav('COPY', '/dav/calendars/ada/default/mine.ics', ['Destination' => 'http://localhost'.$url.'mine.ics', 'Overwrite' => 'F'])->assertForbidden();
        $this->dav('MOVE', '/dav/calendars/ada/default/mine.ics', ['Destination' => 'http://localhost'.$url.'mine.ics', 'Overwrite' => 'F'])->assertForbidden();

        $this->assertSame($before, $this->tokenState($holiday));
        $this->assertSame($etag, (string) $this->dav('GET', $url.$event)->assertOk()->headers->get('ETag'));
        $this->assertSame(65, $this->objects($holiday)->count());

        // A copy out is a read, as from a read-only share.
        $copied = $this->dav('COPY', $url.$event, ['Destination' => 'http://localhost/dav/calendars/ada/default/copied.ics', 'Overwrite' => 'F']);
        $this->assertContains($copied->getStatusCode(), [201, 204]);
        $this->assertSame(65, $this->objects($holiday)->count());

        // The default calendar is unchanged.
        $this->assertContains('write-content', $this->privileges($this->body($this->dav('PROPFIND', '/dav/calendars/ada/default/', ['Depth' => '0'], $this->propfind('<d:current-user-privilege-set/>'))->assertStatus(207))));
    }

    public function test_the_owner_can_rename_and_delete_the_calendar(): void
    {
        $holiday = $this->create('CA-ON', 'holidays-ca-on');
        $url = $this->url('holidays-ca-on');

        $renamed = $this->body($this->dav('PROPPATCH', $url, ['Content-Type' => 'application/xml'],
            '<?xml version="1.0"?><d:propertyupdate xmlns:d="DAV:" xmlns:a="http://apple.com/ns/ical/"><d:set><d:prop><d:displayname>Ontario</d:displayname><a:calendar-color>#FF0000</a:calendar-color></d:prop></d:set></d:propertyupdate>')
            ->assertStatus(207));
        $this->assertStringContainsString('200', $renamed);
        $this->assertSame('Ontario', DB::table('calendarinstances')->where('calendarid', $holiday->calendar_id)->value('displayname'));

        $this->dav('DELETE', $url)->assertNoContent();

        $this->assertNull(HolidayCalendar::query()->find($holiday->getKey()));
        $this->assertSame(0, DB::table('calendarobjects')->where('calendarid', $holiday->calendar_id)->count());
    }

    public function test_a_holiday_calendar_is_shared_read_only(): void
    {
        $holiday = $this->create('CA-ON', 'holidays-ca-on');
        $bob = $this->user('bob', 'Bob Baker');
        $bobToken = $this->token($bob);
        $instance = CalendarInstance::query()->where('calendarid', $holiday->calendar_id)->where('access', CalendarInstance::OWNER)->firstOrFail();
        $sharing = app(SharingService::class);

        try {
            $sharing->share($instance, $bob, SharingService::READ_WRITE);
            $this->fail('A read-write share should be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('A holiday calendar can only be shared read only.', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('calendarinstances')->where('principaluri', 'principals/bob')->where('calendarid', $holiday->calendar_id)->count());

        $share = $sharing->share($instance, $bob, SharingService::READ);
        $this->expectException(InvalidArgumentException::class);

        try {
            $sharing->changeAccess($instance, $bob, SharingService::READ_WRITE);
        } finally {
            $this->assertSame(SharingService::READ, (int) DB::table('calendarinstances')->where('id', $share->getKey())->value('access'));
            $url = '/dav/calendars/bob/'.$share->uri.'/';
            $event = HolidayEvents::uid($holiday->calendar_id, 2027, 'canadaDay').'.ics';
            $this->call('GET', $url.$event, [], [], [], ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode('bob:'.$bobToken)])->assertOk();
            $this->call('DELETE', $url.$event, [], [], [], ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode('bob:'.$bobToken)])->assertForbidden();
        }
    }

    public function test_deleting_the_user_deletes_their_holiday_calendars(): void
    {
        $holiday = $this->create('CA-ON', 'holidays-ca-on');

        app(UserService::class)->delete($this->ada);

        $this->assertSame(0, HolidayCalendar::query()->count());
        $this->assertSame(0, DB::table('calendarobjects')->where('calendarid', $holiday->calendar_id)->count());
    }

    public function test_invalid_choices_are_refused(): void
    {
        $service = app(HolidayCalendarService::class);

        foreach ([
            ['XX', null, null, 'That country or region has no holiday data.'],
            ['US-NYSE', null, null, 'That country or region has no holiday data.'],
            ['CA', 'xx_XX', null, 'That language is not available for holiday names.'],
            ['CA', null, [], 'Choose at least one holiday type.'],
            ['CA', null, ['official', 'birthday'], 'Choose at least one holiday type.'],
        ] as [$region, $locale, $types, $message]) {
            try {
                $service->create($this->ada, 'holidays', 'Holidays', '#3366CC', $region, $locale, $types);
                $this->fail('Expected '.$message);
            } catch (InvalidArgumentException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }

        try {
            $service->create($this->ada, 'default', 'Holidays', '#3366CC', 'CA');
            $this->fail('A taken URI should be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('That URI is already used.', $exception->getMessage());
        }

        $this->assertSame(0, HolidayCalendar::query()->count());
        $this->assertSame(1, DB::table('calendarinstances')->where('principaluri', 'principals/ada')->count());
    }

    public function test_the_catalog_names_every_country_and_region(): void
    {
        $catalog = app(HolidayCatalog::class);
        $named = $catalog->namedRegions();

        foreach ($catalog->codes() as $code) {
            $this->assertTrue($catalog->isLocale($catalog->defaultLocale($code)), $code.' has no Yasumi locale');

            if (str_contains($code, '-')) {
                $this->assertContains($code, $named, $code.' has no name');
            } else {
                $this->assertNotSame($code, $catalog->countryName($code, 'en'), $code.' has no country name');
            }
        }

        $this->assertSame([], array_values(array_diff($named, $catalog->codes())));
        $this->assertNotContains('US-NYSE', $catalog->codes());
        $this->assertSame('Канада', $catalog->countries('uk')['CA']);
        $this->assertSame('Україна', $catalog->countries('uk')['UA']);

        app()->setLocale('uk');
        $this->assertSame('Канада (Онтаріо)', $catalog->label('CA-ON'));
        $this->assertSame('Онтаріо', $catalog->regions('CA')['CA-ON']);
        app()->setLocale('en');
        $this->assertSame('Canada (Ontario)', $catalog->label('CA-ON'));
        $this->assertSame([], $catalog->regions('UA'));
        $this->assertSame('fr_CA', $catalog->defaultLocale('CA-QC'));
        $this->assertSame('en_CA', $catalog->defaultLocale('CA-ON'));
    }

    /**
     * @param  list<string>|null  $types
     */
    private function create(string $region, string $uri, ?string $locale = null, ?array $types = null): HolidayCalendar
    {
        return app(HolidayCalendarService::class)->create($this->ada, $uri, 'Holidays', '#3366CC', $region, $locale, $types);
    }

    /**
     * @return Collection<int, object>
     */
    private function objects(HolidayCalendar $holiday): Collection
    {
        return DB::table('calendarobjects')->where('calendarid', $holiday->calendar_id)->get(['uri']);
    }

    private function event(HolidayCalendar $holiday, string $uri): string
    {
        $uriOf = (string) DB::table('calendarinstances')->where('calendarid', $holiday->calendar_id)->where('access', 1)->value('uri');

        // Long lines are folded (RFC 5545 3.1).
        return str_replace("\r\n ", '', $this->body($this->dav('GET', $this->url($uriOf).$uri)->assertOk()));
    }

    /**
     * @return array{synctoken: int, changes: int}
     */
    private function tokenState(HolidayCalendar $holiday): array
    {
        return [
            'synctoken' => (int) DB::table('calendars')->where('id', $holiday->calendar_id)->value('synctoken'),
            'changes' => DB::table('calendarchanges')->where('calendarid', $holiday->calendar_id)->count(),
        ];
    }

    private function url(string $uri): string
    {
        return '/dav/calendars/ada/'.$uri.'/';
    }

    private function user(string $username, string $name): User
    {
        return app(UserService::class)->create([
            'username' => $username,
            'display_name' => $name,
            'email' => $username.'@example.com',
        ]);
    }

    private function token(User $user): string
    {
        return app(AppPasswordService::class)->issue($user, 'phone')['token'];
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function dav(string $method, string $uri, array $headers = [], ?string $body = null): TestResponse
    {
        $server = ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode('ada:'.$this->adaToken)];

        foreach ($headers as $name => $value) {
            $server[$name === 'Content-Type' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call($method, $uri, [], [], [], $server, $body);
    }

    private function sync(string $url, string $token): TestResponse
    {
        return $this->dav('REPORT', $url, ['Depth' => '0', 'Content-Type' => 'application/xml'],
            '<?xml version="1.0"?><d:sync-collection xmlns:d="DAV:"><d:sync-token>'.htmlspecialchars($token, ENT_XML1).'</d:sync-token><d:sync-level>1</d:sync-level><d:prop><d:getetag/></d:prop></d:sync-collection>')
            ->assertStatus(207);
    }

    private function syncTokenOf(string $url): string
    {
        $body = $this->body($this->dav('PROPFIND', $url, ['Depth' => '0', 'Content-Type' => 'application/xml'], $this->propfind('<d:sync-token/>'))->assertStatus(207));
        $this->assertSame(1, preg_match('#<[^>]*sync-token>([^<]+)</[^>]*sync-token>#', $body, $matches), $body);

        return html_entity_decode($matches[1], ENT_XML1);
    }

    /**
     * @return list<string>
     */
    private function hrefs(TestResponse $response): array
    {
        preg_match_all('#<[^>]*href>([^<]+\.ics)</[^>]*href>#', $this->body($response), $matches);

        return $matches[1];
    }

    /**
     * @return list<string>
     */
    private function privileges(string $body): array
    {
        preg_match_all('#<([A-Za-z0-9]+):privilege>\s*<\1:([A-Za-z0-9-]+)\s*/>#', $body, $matches);

        return $matches[2];
    }

    private function body(TestResponse $response): string
    {
        return $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : (string) $response->getContent();
    }

    private function propfind(string $properties): string
    {
        return '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop>'.$properties.'</d:prop></d:propfind>';
    }

    private function deadProperty(): string
    {
        return '<?xml version="1.0"?><d:propertyupdate xmlns:d="DAV:" xmlns:x="urn:example"><d:set><d:prop><x:note>x</x:note></d:prop></d:set></d:propertyupdate>';
    }

    private function item(string $uid): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\nBEGIN:VEVENT\r\nUID:{$uid}\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20270101T100000Z\r\nDTEND:20270101T110000Z\r\nSUMMARY:Item\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }
}
