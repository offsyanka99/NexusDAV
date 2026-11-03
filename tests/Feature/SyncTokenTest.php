<?php

namespace Tests\Feature;

use App\Models\AddressBook;
use App\Models\CalendarInstance;
use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\Dav\CollectionService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * PR 14: sync tokens that cannot be trusted (issue #2).
 */
class SyncTokenTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = 'http://sabre.io/ns/sync/';

    private User $ada;

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ada = app(UserService::class)->create(['username' => 'ada', 'display_name' => 'Ada', 'email' => 'ada@example.com']);
        $this->password = app(AppPasswordService::class)->issue($this->ada, 'phone')['token'];
    }

    /**
     * Client pass 1, step 6: the old token used to get "nothing changed", even
     * with the same change number and ctag as the old calendar.
     */
    public function test_a_calendar_recreated_in_the_panel_refuses_the_old_token_and_a_full_sync_shows_only_new_items(): void
    {
        foreach ([[1, 0], [2, 1], [5, 5]] as [$oldItems, $newItems]) {
            $this->panelCalendar();
            $this->putEvents('old', $oldItems);
            $oldToken = $this->syncToken($this->sync('/dav/calendars/ada/recreate/')->assertStatus(207));
            $oldCtag = $this->ctag('/dav/calendars/ada/recreate/');

            $this->deletePanelCalendar();
            $this->panelCalendar();
            $this->putEvents('new', $newItems);

            $this->assertNotSame($oldCtag, $this->ctag('/dav/calendars/ada/recreate/'), "ctag after $oldItems/$newItems");
            $this->assertInvalidToken($this->sync('/dav/calendars/ada/recreate/', $oldToken), "old token after $oldItems/$newItems");

            $full = $this->body($this->sync('/dav/calendars/ada/recreate/')->assertStatus(207));
            $this->assertSame($newItems, substr_count($full, '.ics</d:href>'));
            $this->assertStringNotContainsString('old-1.ics', $full);

            $this->deletePanelCalendar();
        }
    }

    public function test_a_calendar_recreated_by_a_client_refuses_the_old_token(): void
    {
        $this->dav('MKCALENDAR', '/dav/calendars/ada/recreate/')->assertCreated();
        $this->putEvents('old', 2);
        $oldToken = $this->syncToken($this->sync('/dav/calendars/ada/recreate/'));

        $this->dav('DELETE', '/dav/calendars/ada/recreate/')->assertNoContent();
        $this->dav('MKCALENDAR', '/dav/calendars/ada/recreate/')->assertCreated();
        $this->putEvents('new', 2);

        $this->assertInvalidToken($this->sync('/dav/calendars/ada/recreate/', $oldToken), 'client re-create');
    }

    public function test_an_address_book_recreated_with_the_same_uri_refuses_the_old_token_and_changes_its_ctag(): void
    {
        foreach ([[1, 0], [3, 3]] as [$oldItems, $newItems]) {
            $this->makeAddressBook();
            $this->putCards('old', $oldItems);
            $oldToken = $this->syncToken($this->sync('/dav/addressbooks/ada/recreate/'));
            $oldCtag = $this->ctag('/dav/addressbooks/ada/recreate/');

            $this->dav('DELETE', '/dav/addressbooks/ada/recreate/')->assertNoContent();
            $this->makeAddressBook();
            $this->putCards('new', $newItems);

            $this->assertNotSame($oldCtag, $this->ctag('/dav/addressbooks/ada/recreate/'), "ctag after $oldItems/$newItems");
            $this->assertInvalidToken($this->sync('/dav/addressbooks/ada/recreate/', $oldToken), "old token after $oldItems/$newItems");

            $full = $this->body($this->sync('/dav/addressbooks/ada/recreate/')->assertStatus(207));
            $this->assertSame($newItems, substr_count($full, '.vcf</d:href>'));

            $this->dav('DELETE', '/dav/addressbooks/ada/recreate/')->assertNoContent();
        }
    }

    public function test_tokens_of_another_collection_ahead_tokens_and_malformed_tokens_get_valid_sync_token(): void
    {
        $this->panelCalendar();
        $this->putEvents('e', 2);
        $this->makeAddressBook();
        $this->putCards('c', 2);

        foreach (['/dav/calendars/ada/' => ['default', 'recreate'], '/dav/addressbooks/ada/' => ['default', 'recreate']] as $home => [$other, $tested]) {
            $url = $home.$tested.'/';
            $current = $this->syncToken($this->sync($url));
            $this->assertMatchesRegularExpression('#^'.preg_quote(self::PREFIX, '#').'\d+-\d+$#', $current);
            [$id, $number] = explode('-', substr($current, strlen(self::PREFIX)));
            $otherToken = $this->syncToken($this->sync($home.$other.'/'));

            $bad = [
                'another collection' => $otherToken,
                'ahead' => self::PREFIX.$id.'-'.((int) $number + 1),
                'ahead, legacy form' => self::PREFIX.((int) $number + 1),
            ];

            foreach (['abc', $id.'-', '-'.$number, $id.'-'.$number.'-1', '0', $id.'-0', '0'.$id.'-'.$number, 'x-1', $id.' -'.$number,
                '12345678901', $id.'-12345678901', '1.5', 'home-1.W10', str_repeat('9', 300), '١-١'] as $malformed) {
                $bad['malformed '.$malformed] = self::PREFIX.$malformed;
            }

            $bad['foreign prefix'] = 'urn:example:'.$id.'-'.$number;

            foreach ($bad as $case => $token) {
                $this->assertInvalidToken($this->sync($url, $token), $url.' '.$case);
            }

            // The real token still works afterwards.
            $this->sync($url, $current)->assertStatus(207);
        }
    }

    /**
     * Implementation plan v1.x, Decision 20: tokens issued before PR 14 were the
     * bare change number. They keep working, and the reply moves the client to
     * the new form.
     */
    public function test_a_legacy_token_is_accepted_while_it_is_not_ahead_and_the_reply_has_the_new_form(): void
    {
        $this->panelCalendar();
        $this->putEvents('e', 3);
        $this->makeAddressBook();
        $this->putCards('c', 3);

        foreach (['/dav/calendars/ada/recreate/' => '.ics', '/dav/addressbooks/ada/recreate/' => '.vcf'] as $url => $extension) {
            [$id, $number] = explode('-', substr($this->syncToken($this->sync($url)), strlen(self::PREFIX)));

            $quiet = $this->sync($url, self::PREFIX.$number)->assertStatus(207);
            $this->assertSame(self::PREFIX.$id.'-'.$number, $this->syncToken($quiet));
            $this->assertSame(0, substr_count($this->body($quiet), $extension.'</d:href>'));

            $older = $this->sync($url, self::PREFIX.((int) $number - 1))->assertStatus(207);
            $this->assertSame(1, substr_count($this->body($older), $extension.'</d:href>'), $url);
            $this->assertSame(self::PREFIX.$id.'-'.$number, $this->syncToken($older));
        }
    }

    public function test_an_incremental_sync_reports_changes_and_deletions_with_the_new_token(): void
    {
        $this->panelCalendar();
        $this->putEvents('e', 2);
        $token = $this->syncToken($this->sync('/dav/calendars/ada/recreate/'));

        $this->dav('DELETE', '/dav/calendars/ada/recreate/e-1.ics')->assertNoContent();
        $this->putEvents('f', 1);

        $body = $this->body($this->sync('/dav/calendars/ada/recreate/', $token)->assertStatus(207));
        $this->assertStringContainsString('e-1.ics', $body);
        $this->assertStringContainsString('404', $body);
        $this->assertStringContainsString('f-1.ics', $body);
        $this->assertStringNotContainsString('e-2.ics', $body);
    }

    public function test_propfind_reports_the_token_and_ctag_with_the_collection_id(): void
    {
        $this->panelCalendar();
        $calendarId = (int) CalendarInstance::query()->where('uri', 'recreate')->value('calendarid');
        $this->makeAddressBook();
        $bookId = (int) AddressBook::query()->where('uri', 'recreate')->value('id');

        foreach (['/dav/calendars/ada/recreate/' => $calendarId, '/dav/addressbooks/ada/recreate/' => $bookId] as $url => $id) {
            $body = $this->body($this->dav('PROPFIND', $url, ['Depth' => '0', 'Content-Type' => 'application/xml'],
                '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/"><d:prop><d:sync-token/><cs:getctag/></d:prop></d:propfind>')->assertStatus(207));

            $this->assertStringContainsString('<d:sync-token>'.self::PREFIX.$id.'-1</d:sync-token>', $body, $url);
            $this->assertStringContainsString('getctag>'.self::PREFIX.$id.'-1</', $body, $url);
        }
    }

    public function test_the_homes_report_a_recreated_child_as_changed(): void
    {
        $this->panelCalendar();
        $this->putEvents('e', 1);
        $this->makeAddressBook();
        $this->putCards('c', 1);

        $calendarHome = $this->syncToken($this->sync('/dav/calendars/ada/'));
        $bookHome = $this->syncToken($this->sync('/dav/addressbooks/ada/'));

        $this->deletePanelCalendar();
        $this->panelCalendar();
        $this->putEvents('n', 1);
        $this->dav('DELETE', '/dav/addressbooks/ada/recreate/')->assertNoContent();
        $this->makeAddressBook();
        $this->putCards('n', 1);

        foreach (['/dav/calendars/ada/' => $calendarHome, '/dav/addressbooks/ada/' => $bookHome] as $home => $token) {
            $body = $this->body($this->sync($home, $token)->assertStatus(207));
            // Reported as modified: listed with its properties, not as a deleted member.
            $this->assertMatchesRegularExpression('#<d:href>'.preg_quote($home, '#').'recreate/?</d:href>\s*<d:propstat>#', $body, $home);
            $this->assertStringNotContainsString($home.'default', $body, $home);
            $this->assertDoesNotMatchRegularExpression('#</d:href>\s*<d:status>[^<]*404#', $body, $home);
        }
    }

    private function panelCalendar(): void
    {
        app(CollectionService::class)->createCalendar($this->ada, 'recreate', 'Recreate', null, '#112233', false, false);
    }

    private function deletePanelCalendar(): void
    {
        app(CollectionService::class)->deleteCalendar($this->ada, CalendarInstance::query()->where('uri', 'recreate')->sole());
    }

    private function makeAddressBook(): void
    {
        $this->dav('MKCOL', '/dav/addressbooks/ada/recreate/', ['Content-Type' => 'application/xml'],
            '<?xml version="1.0"?><d:mkcol xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav"><d:set><d:prop><d:resourcetype><d:collection/><card:addressbook/></d:resourcetype><d:displayname>Recreate</d:displayname></d:prop></d:set></d:mkcol>')
            ->assertCreated();
    }

    private function putEvents(string $prefix, int $count): void
    {
        for ($n = 1; $n <= $count; $n++) {
            $uid = $prefix.'-'.$n;
            $this->dav('PUT', '/dav/calendars/ada/recreate/'.$uid.'.ics', ['Content-Type' => 'text/calendar'],
                "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//NexusDAV//test//EN\r\nBEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260101T100000Z\r\nSUMMARY:$uid\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n")
                ->assertCreated();
        }
    }

    private function putCards(string $prefix, int $count): void
    {
        for ($n = 1; $n <= $count; $n++) {
            $uid = $prefix.'-'.$n;
            $this->dav('PUT', '/dav/addressbooks/ada/recreate/'.$uid.'.vcf', ['Content-Type' => 'text/vcard'],
                "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:$uid\r\nFN:$uid\r\nN:$uid;;;;\r\nEND:VCARD\r\n")
                ->assertCreated();
        }
    }

    private function sync(string $url, ?string $token = null): TestResponse
    {
        $token = $token === null ? '' : htmlspecialchars($token, ENT_XML1);

        return $this->dav('REPORT', $url, ['Depth' => '0', 'Content-Type' => 'application/xml'],
            '<?xml version="1.0"?><d:sync-collection xmlns:d="DAV:"><d:sync-token>'.$token.'</d:sync-token><d:sync-level>1</d:sync-level><d:prop><d:getetag/></d:prop></d:sync-collection>');
    }

    private function ctag(string $url): string
    {
        $body = $this->body($this->dav('PROPFIND', $url, ['Depth' => '0', 'Content-Type' => 'application/xml'],
            '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/"><d:prop><cs:getctag/></d:prop></d:propfind>'));
        $this->assertSame(1, preg_match('#getctag>([^<]+)</#', $body, $matches), $body);

        return $matches[1];
    }

    private function syncToken(TestResponse $response): string
    {
        $body = $this->body($response);
        $this->assertSame(1, preg_match('#<d:sync-token>([^<]+)</d:sync-token>#', $body, $matches), $body);

        return html_entity_decode($matches[1], ENT_XML1);
    }

    private function assertInvalidToken(TestResponse $response, string $case): void
    {
        $body = $this->body($response);
        $this->assertSame(403, $response->getStatusCode(), $case.': '.$body);
        $this->assertStringContainsString('valid-sync-token', $body, $case);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function dav(string $method, string $url, array $headers = [], ?string $body = null): TestResponse
    {
        $server = ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode('ada:'.$this->password)];

        foreach ($headers as $name => $value) {
            $server[$name === 'Content-Type' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call($method, $url, [], [], [], $server, $body);
    }

    private function body(TestResponse $response): string
    {
        return $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : (string) $response->getContent();
    }
}
