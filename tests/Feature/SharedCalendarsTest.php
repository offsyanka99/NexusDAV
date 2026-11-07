<?php

namespace Tests\Feature;

use App\Models\CalendarInstance;
use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\Dav\DavServerFactory;
use App\Services\Dav\SharingService;
use App\Services\SettingsService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * PR 23: a shared calendar in the sharee's home, with access from the share
 * rather than DAV:owner, and the sharing switch.
 */
class SharedCalendarsTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = 'http://sabre.io/ns/sync/';

    private User $ada;

    private User $bob;

    private User $carol;

    private string $adaToken;

    private string $bobToken;

    private string $carolToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ada = $this->user('ada', 'Ada Lovelace');
        $this->bob = $this->user('bob', 'Bob Baker');
        $this->carol = $this->user('carol', 'Carol Danvers');
        $this->adaToken = $this->token($this->ada);
        $this->bobToken = $this->token($this->bob);
        $this->carolToken = $this->token($this->carol);
    }

    public function test_a_shared_calendar_is_listed_with_the_owners_token_and_components(): void
    {
        $this->putItem($this->ada, $this->adaToken, '/dav/calendars/ada/default/meet.ics', $this->item('meet', 'VEVENT', 'Meet'));
        $share = $this->share(SharingService::READ);
        $url = $this->shareUrl($share);

        $home = $this->body($this->dav('PROPFIND', '/dav/calendars/bob/', 'bob', $this->bobToken, ['Depth' => '1'], $this->propfind('<d:displayname/>'))->assertStatus(207));
        $this->assertStringContainsString('/dav/calendars/bob/'.$share->uri, $home);
        $this->assertStringContainsString('Calendar (Ada Lovelace)', $home);

        $props = $this->body($this->dav('PROPFIND', $url, 'bob', $this->bobToken, ['Depth' => '0'], $this->calendarProps())->assertStatus(207));
        $this->assertStringContainsString('VEVENT', $props);
        $this->assertStringContainsString('VTODO', $props);
        $this->assertStringContainsString('VJOURNAL', $props);
        $this->assertSame(1, preg_match('#<[^>]*owner>(.*?)</[^>]*owner>#s', $props, $owner), $props);
        $this->assertStringContainsString('/dav/principals/bob/', $owner[1]);
        $this->assertStringNotContainsString('principals/ada', $owner[1]);

        $ownerToken = $this->syncTokenOf('/dav/calendars/ada/default/', 'ada', $this->adaToken);
        $this->assertSame($ownerToken, $this->syncTokenOf($url, 'bob', $this->bobToken));
        $this->assertSame($this->ctag('/dav/calendars/ada/default/', 'ada', $this->adaToken), $this->ctag($url, 'bob', $this->bobToken));

        $got = $this->dav('GET', $url.'meet.ics', 'bob', $this->bobToken)->assertOk();
        $this->assertStringContainsString('SUMMARY:Meet', $this->body($got));
        $this->dav('HEAD', $url.'meet.ics', 'bob', $this->bobToken)->assertOk();

        $query = $this->body($this->dav('REPORT', $url, 'bob', $this->bobToken, ['Depth' => '1', 'Content-Type' => 'application/xml'], $this->calendarQuery())->assertStatus(207));
        $this->assertStringContainsString('meet.ics', $query);
        $multi = $this->body($this->dav('REPORT', $url, 'bob', $this->bobToken, ['Depth' => '1', 'Content-Type' => 'application/xml'], $this->multiget($url.'meet.ics'))->assertStatus(207));
        $this->assertStringContainsString('SUMMARY:Meet', $multi);

        $homeSync = $this->sync('/dav/calendars/bob/', 'bob', $this->bobToken)->assertStatus(207);
        $this->assertStringContainsString('/dav/calendars/bob/'.$share->uri, $this->body($homeSync));
    }

    public function test_a_read_only_share_refuses_writes_and_lists_read_privileges(): void
    {
        $this->putItem($this->ada, $this->adaToken, '/dav/calendars/ada/default/meet.ics', $this->item('meet', 'VEVENT', 'Meet'));
        $share = $this->share(SharingService::READ);
        $url = $this->shareUrl($share);
        $before = $this->tokenState();

        $options = $this->dav('OPTIONS', $url, 'bob', $this->bobToken)->assertOk();
        $dav = (string) $options->headers->get('DAV');
        $this->assertStringNotContainsString('resource-sharing', $dav);
        $this->assertStringNotContainsString('calendarserver-sharing', $dav);
        $server = app(DavServerFactory::class)->make();
        $this->assertNull($server->getPlugin('sharing'));
        $this->assertNull($server->getPlugin('caldav-sharing'));

        $collection = $this->privileges($this->body($this->dav('PROPFIND', $url, 'bob', $this->bobToken, ['Depth' => '0'], $this->propfind('<d:current-user-privilege-set/><d:acl/>'))->assertStatus(207)));
        $this->assertContains('read', $collection);
        $this->assertContains('write-properties', $collection);
        foreach (['write', 'bind', 'unbind', 'write-content', 'share'] as $privilege) {
            $this->assertNotContains($privilege, $collection);
        }

        $item = $this->privileges($this->body($this->dav('PROPFIND', $url.'meet.ics', 'bob', $this->bobToken, ['Depth' => '0'], $this->propfind('<d:current-user-privilege-set/>'))->assertStatus(207)));
        $this->assertContains('read', $item);
        foreach (['write', 'write-properties', 'write-content', 'bind', 'unbind'] as $privilege) {
            $this->assertNotContains($privilege, $item);
        }

        $acl = $this->body($this->dav('PROPFIND', $url, 'bob', $this->bobToken, ['Depth' => '0'], $this->propfind('<d:acl/>'))->assertStatus(207));
        $this->assertStringNotContainsString('authenticated', $acl);
        $this->assertStringNotContainsString('calendar-proxy', $acl);
        $this->assertStringNotContainsString('share', strtolower($acl));

        $this->dav('PUT', $url.'new.ics', 'bob', $this->bobToken, ['Content-Type' => 'text/calendar'], $this->item('new', 'VEVENT'))->assertForbidden();
        $etag = $this->dav('GET', $url.'meet.ics', 'bob', $this->bobToken)->assertOk()->headers->get('ETag');
        $this->dav('PUT', $url.'meet.ics', 'bob', $this->bobToken, ['Content-Type' => 'text/calendar', 'If-Match' => (string) $etag], $this->item('meet', 'VEVENT', 'Changed'))->assertForbidden();
        $this->dav('DELETE', $url.'meet.ics', 'bob', $this->bobToken)->assertForbidden();
        $this->dav('PROPPATCH', $url.'meet.ics', 'bob', $this->bobToken, ['Content-Type' => 'application/xml'], $this->deadProperty('item'))->assertForbidden();
        $this->dav('MOVE', $url.'meet.ics', 'bob', $this->bobToken, [
            'Destination' => 'http://localhost/dav/calendars/bob/default/moved.ics',
            'Overwrite' => 'F',
        ])->assertForbidden();
        $this->dav('LOCK', $url.'meet.ics', 'bob', $this->bobToken, ['Content-Type' => 'application/xml'], $this->lockInfo())->assertForbidden();

        $copied = $this->dav('COPY', $url.'meet.ics', 'bob', $this->bobToken, [
            'Destination' => 'http://localhost/dav/calendars/bob/default/copied.ics',
            'Overwrite' => 'F',
        ]);
        $this->assertContains($copied->getStatusCode(), [201, 204], $this->body($copied));
        $this->dav('GET', '/dav/calendars/ada/default/meet.ics', 'ada', $this->adaToken)->assertOk();
        $this->assertStringContainsString('SUMMARY:Meet', $this->body($this->dav('GET', '/dav/calendars/bob/default/copied.ics', 'bob', $this->bobToken)->assertOk()));

        $this->assertSame($before, $this->tokenState());
        $this->assertSame(1, DB::table('calendarobjects')->where('uri', 'meet.ics')->count());
    }

    public function test_a_read_write_share_can_change_events_tasks_and_notes(): void
    {
        $share = $this->share(SharingService::READ_WRITE);
        $url = $this->shareUrl($share);
        $carol = $this->shareWith($this->carol, SharingService::READ);

        $privileges = $this->privileges($this->body($this->dav('PROPFIND', $url, 'bob', $this->bobToken, ['Depth' => '0'], $this->propfind('<d:current-user-privilege-set/>'))->assertStatus(207)));
        $this->assertContains('write', $privileges);
        $this->assertContains('bind', $privileges);
        $this->assertContains('unbind', $privileges);
        $this->assertContains('write-content', $privileges);
        $this->assertNotContains('share', $privileges);

        $created = $this->putItem($this->bob, $this->bobToken, $url.'meet.ics', $this->item('meet', 'VEVENT', 'From Bob'));
        $etag = (string) $created->headers->get('ETag');
        $this->assertNotSame('', $etag);
        $this->putItem($this->bob, $this->bobToken, $url.'task.ics', $this->item('task', 'VTODO', 'Task'));
        $this->putItem($this->bob, $this->bobToken, $url.'note.ics', $this->item('note', 'VJOURNAL', 'Note'));

        $owner = $this->body($this->dav('GET', '/dav/calendars/ada/default/meet.ics', 'ada', $this->adaToken)->assertOk());
        $this->assertStringContainsString('SUMMARY:From Bob', $owner);
        $this->assertSame($etag, $this->dav('GET', '/dav/calendars/ada/default/meet.ics', 'ada', $this->adaToken)->headers->get('ETag'));

        $token = $this->syncToken($this->sync($url, 'bob', $this->bobToken)->assertStatus(207));
        $this->putItem($this->ada, $this->adaToken, '/dav/calendars/ada/default/owner.ics', $this->item('owner', 'VEVENT', 'From Ada'));
        $delta = $this->body($this->sync($url, 'bob', $this->bobToken, $token)->assertStatus(207));
        $this->assertStringContainsString('owner.ics', $delta);
        $this->assertStringNotContainsString('meet.ics', $delta);

        $carolDelta = $this->body($this->sync($this->shareUrl($carol), 'carol', $this->carolToken)->assertStatus(207));
        $this->assertStringContainsString('meet.ics', $carolDelta);
        $this->assertStringContainsString('owner.ics', $carolDelta);

        $updated = $this->dav('PUT', $url.'meet.ics', 'bob', $this->bobToken, ['Content-Type' => 'text/calendar', 'If-Match' => $etag], $this->item('meet', 'VEVENT', 'Edited'));
        $updated->assertNoContent();
        $this->dav('PUT', $url.'meet.ics', 'bob', $this->bobToken, ['Content-Type' => 'text/calendar', 'If-Match' => $etag], $this->item('meet', 'VEVENT', 'Again'))->assertStatus(412);
        $this->assertStringContainsString('SUMMARY:Edited', $this->body($this->dav('GET', '/dav/calendars/ada/default/meet.ics', 'ada', $this->adaToken)->assertOk()));

        $this->dav('DELETE', $url.'task.ics', 'bob', $this->bobToken)->assertNoContent();
        $this->dav('GET', '/dav/calendars/ada/default/task.ics', 'ada', $this->adaToken)->assertNotFound();

        $moved = $this->dav('MOVE', $url.'note.ics', 'bob', $this->bobToken, [
            'Destination' => 'http://localhost/dav/calendars/bob/default/note.ics',
            'Overwrite' => 'F',
        ]);
        $this->assertContains($moved->getStatusCode(), [201, 204], $this->body($moved));
        $this->dav('GET', '/dav/calendars/ada/default/note.ics', 'ada', $this->adaToken)->assertNotFound();
        $this->dav('GET', '/dav/calendars/bob/default/note.ics', 'bob', $this->bobToken)->assertOk();
    }

    public function test_the_sharees_name_color_and_order_stay_on_their_row(): void
    {
        $share = $this->share(SharingService::READ);
        $url = $this->shareUrl($share);
        $owner = CalendarInstance::query()->where('principaluri', 'principals/ada')->where('access', 1)->firstOrFail();
        $before = $this->tokenState();

        $this->dav('PROPPATCH', $url, 'bob', $this->bobToken, ['Content-Type' => 'application/xml'], $this->rename())->assertStatus(207);

        $owner->refresh();
        $share->refresh();
        $this->assertSame('Calendar', $owner->displayname);
        $this->assertSame('#1A73E8', $owner->calendarcolor);
        $this->assertSame(0, (int) $owner->calendarorder);
        $this->assertSame('Mine', $share->displayname);
        $this->assertSame('#FF00AA', $share->calendarcolor);
        $this->assertSame(4, (int) $share->calendarorder);
        $this->assertSame($before, $this->tokenState());

        $dead = $this->dav('PROPPATCH', $url, 'bob', $this->bobToken, ['Content-Type' => 'application/xml'], $this->deadProperty('sharee'));
        $dead->assertStatus(207);
        $this->assertStringNotContainsString('403', $this->body($dead));
        $own = $this->body($this->dav('PROPFIND', '/dav/calendars/ada/default/', 'ada', $this->adaToken, ['Depth' => '0'], $this->propfind('<x:note xmlns:x="urn:example"/>'))->assertStatus(207));
        $this->assertStringNotContainsString('sharee', $own);
        $this->assertSame($before, $this->tokenState());
    }

    public function test_deleting_the_shared_calendar_removes_only_the_share(): void
    {
        $this->putItem($this->ada, $this->adaToken, '/dav/calendars/ada/default/meet.ics', $this->item('meet', 'VEVENT', 'Meet'));
        $share = $this->share(SharingService::READ);
        $url = $this->shareUrl($share);
        $this->dav('PROPPATCH', $url, 'bob', $this->bobToken, ['Content-Type' => 'application/xml'], $this->deadProperty('gone'))->assertStatus(207);
        $path = 'calendars/bob/'.$share->uri;
        $this->assertGreaterThan(0, DB::table('propertystorage')->where('path', $path)->count());

        $home = $this->syncToken($this->sync('/dav/calendars/bob/', 'bob', $this->bobToken)->assertStatus(207));
        $before = $this->tokenState();

        $this->dav('DELETE', $url, 'bob', $this->bobToken)->assertNoContent();

        $this->assertSame(0, CalendarInstance::query()->where('principaluri', 'principals/bob')->where('access', '!=', 1)->count());
        $this->assertSame(1, DB::table('calendarobjects')->where('uri', 'meet.ics')->count());
        $this->assertSame($before, $this->tokenState());
        $this->assertSame(0, DB::table('propertystorage')->where('path', $path)->orWhere('path', 'like', $path.'/%')->count());
        $this->dav('GET', '/dav/calendars/ada/default/meet.ics', 'ada', $this->adaToken)->assertOk();
        $this->dav('PROPFIND', $url, 'bob', $this->bobToken, ['Depth' => '0'])->assertNotFound();

        $removed = $this->body($this->sync('/dav/calendars/bob/', 'bob', $this->bobToken, $home)->assertStatus(207));
        $this->assertStringContainsString('/dav/calendars/bob/'.$share->uri, $removed);
        $this->assertStringContainsString('404', $removed);
    }

    public function test_clients_cannot_share_and_properties_list_no_other_sharee(): void
    {
        $bob = $this->share(SharingService::READ);
        $carol = $this->shareWith($this->carol, SharingService::READ_WRITE);
        $rows = DB::table('calendarinstances')->count();

        foreach ([
            $this->sharePost(),
            $this->inviteReply(),
            $this->shareResource(),
        ] as $body) {
            $response = $this->dav('POST', $this->shareUrl($bob), 'bob', $this->bobToken, ['Content-Type' => 'application/xml'], $body);
            $response->assertForbidden();
            $this->assertStringContainsString('Sharing is not available from a client.', $this->body($response));
        }

        $typed = $this->dav('POST', $this->shareUrl($bob), 'bob', $this->bobToken, ['Content-Type' => 'application/davsharing+xml'], $this->shareResource());
        $typed->assertForbidden();
        $this->assertSame($rows, DB::table('calendarinstances')->count());

        $props = $this->body($this->dav('PROPFIND', $this->shareUrl($bob), 'bob', $this->bobToken, ['Depth' => '0'], $this->sharingProps())->assertStatus(207));
        $this->assertStringNotContainsString('principals/carol', $props);
        $this->assertStringNotContainsString('Carol', $props);
        $found = $this->found($props);
        $this->assertNotSame('', $found, $props);
        $this->assertStringNotContainsString('allowed-sharing-modes', $found);
        $this->assertStringNotContainsString('shared-owner', $props);
        $this->assertSame(1, preg_match('#resourcetype>(.*?)</[^>]*resourcetype>#s', $props, $type), $props);
        $this->assertStringContainsString('calendar', $type[1]);
        $this->assertStringNotContainsString('shared', $type[1]);

        $owner = $this->body($this->dav('PROPFIND', '/dav/calendars/ada/default/', 'ada', $this->adaToken, ['Depth' => '0'], $this->sharingProps())->assertStatus(207));
        $this->assertStringNotContainsString('principals/bob', $owner);
        $this->assertStringNotContainsString('principals/carol', $owner);
        $this->assertSame($bob->calendarid, $carol->calendarid);
    }

    public function test_the_owner_stays_hidden_and_a_third_user_sees_nothing(): void
    {
        $share = $this->share(SharingService::READ);
        $url = $this->shareUrl($share);

        foreach ([
            '/dav/principals/ada/',
            '/dav/calendars/ada/',
            '/dav/calendars/ada/default/',
            '/dav/addressbooks/ada/',
        ] as $path) {
            $this->dav('PROPFIND', $path, 'bob', $this->bobToken, ['Depth' => '0'])->assertNotFound();
        }

        $this->dav('PROPFIND', $url, 'carol', $this->carolToken, ['Depth' => '1'])->assertNotFound();
        $this->dav('PROPFIND', '/dav/calendars/ada/default/', 'carol', $this->carolToken, ['Depth' => '1'])->assertNotFound();
        $this->dav('GET', $url.'meet.ics', 'carol', $this->carolToken)->assertNotFound();

        $carolHome = $this->body($this->dav('PROPFIND', '/dav/calendars/carol/', 'carol', $this->carolToken, ['Depth' => '1'])->assertStatus(207));
        $this->assertStringNotContainsString($share->uri, $carolHome);
        $this->assertStringNotContainsString('/dav/calendars/ada', $carolHome);
    }

    public function test_the_tasks_and_notes_switches_apply_to_a_shared_calendar(): void
    {
        $this->putItem($this->ada, $this->adaToken, '/dav/calendars/ada/default/task.ics', $this->item('task', 'VTODO', 'Task'));
        $this->putItem($this->ada, $this->adaToken, '/dav/calendars/ada/default/note.ics', $this->item('note', 'VJOURNAL', 'Note'));
        $share = $this->share(SharingService::READ_WRITE);
        $url = $this->shareUrl($share);

        app(SettingsService::class)->save(['tasks' => false, 'notes' => false]);

        $components = $this->body($this->dav('PROPFIND', $url, 'bob', $this->bobToken, ['Depth' => '0'], $this->propfind('<c:supported-calendar-component-set xmlns:c="urn:ietf:params:xml:ns:caldav"/>'))->assertStatus(207));
        $this->assertStringContainsString('VEVENT', $components);
        $this->assertStringNotContainsString('VTODO', $components);
        $this->assertStringNotContainsString('VJOURNAL', $components);

        $this->dav('GET', $url.'task.ics', 'bob', $this->bobToken)->assertOk();
        $this->dav('GET', $url.'note.ics', 'bob', $this->bobToken)->assertOk();
        $this->dav('PUT', $url.'new-task.ics', 'bob', $this->bobToken, ['Content-Type' => 'text/calendar'], $this->item('new-task', 'VTODO'))->assertForbidden();
        $this->dav('PUT', $url.'task.ics', 'bob', $this->bobToken, ['Content-Type' => 'text/calendar'], $this->item('task', 'VTODO', 'Changed'))->assertForbidden();
        $this->dav('PUT', $url.'new-note.ics', 'bob', $this->bobToken, ['Content-Type' => 'text/calendar'], $this->item('new-note', 'VJOURNAL'))->assertForbidden();
        $this->dav('PUT', $url.'event.ics', 'bob', $this->bobToken, ['Content-Type' => 'text/calendar'], $this->item('event', 'VEVENT'))->assertCreated();
        $this->dav('DELETE', $url.'task.ics', 'bob', $this->bobToken)->assertNoContent();

        app(SettingsService::class)->save(['tasks' => true, 'notes' => true]);
        $this->putItem($this->bob, $this->bobToken, $url.'again.ics', $this->item('again', 'VTODO', 'Again'));
        $this->putItem($this->bob, $this->bobToken, $url.'journal.ics', $this->item('journal', 'VJOURNAL', 'Journal'));
        $this->dav('GET', '/dav/calendars/ada/default/again.ics', 'ada', $this->adaToken)->assertOk();
        $this->dav('GET', '/dav/calendars/ada/default/journal.ics', 'ada', $this->adaToken)->assertOk();
    }

    public function test_a_disabled_owner_does_not_hide_the_share(): void
    {
        $this->putItem($this->ada, $this->adaToken, '/dav/calendars/ada/default/meet.ics', $this->item('meet', 'VEVENT', 'Meet'));
        $share = $this->share(SharingService::READ);
        $this->ada->forceFill(['is_active' => false])->save();

        $this->dav('GET', '/dav/calendars/ada/default/meet.ics', 'ada', $this->adaToken)->assertUnauthorized();
        $this->dav('GET', $this->shareUrl($share).'meet.ics', 'bob', $this->bobToken)->assertOk();
        $this->dav('PROPFIND', '/dav/calendars/bob/', 'bob', $this->bobToken, ['Depth' => '1'])->assertStatus(207);
    }

    public function test_sharing_off_hides_shares_and_keeps_the_rows(): void
    {
        $share = $this->share(SharingService::READ_WRITE);
        $url = $this->shareUrl($share);
        $this->dav('PROPFIND', $url, 'bob', $this->bobToken, ['Depth' => '0'])->assertStatus(207);

        app(SettingsService::class)->save(['sharing' => false]);

        $this->assertFalse(app(SettingsService::class)->get('sharing'));
        $this->dav('PROPFIND', $url, 'bob', $this->bobToken, ['Depth' => '0'])->assertNotFound();
        $this->dav('GET', $url, 'bob', $this->bobToken)->assertNotFound();
        $home = $this->body($this->dav('PROPFIND', '/dav/calendars/bob/', 'bob', $this->bobToken, ['Depth' => '1'])->assertStatus(207));
        $this->assertStringNotContainsString('/dav/calendars/bob/'.$share->uri, $home);
        $this->dav('PROPFIND', '/dav/calendars/ada/default/', 'ada', $this->adaToken, ['Depth' => '0'])->assertStatus(207);
        $this->assertSame(1, CalendarInstance::query()->where('principaluri', 'principals/bob')->where('access', '!=', 1)->count());

        app(SettingsService::class)->save(['sharing' => true]);
        $this->dav('PROPFIND', $url, 'bob', $this->bobToken, ['Depth' => '0'])->assertStatus(207);
    }

    public function test_a_set_sharing_variable_locks_the_value(): void
    {
        $share = $this->share(SharingService::READ);
        $url = $this->shareUrl($share);
        $settings = app(SettingsService::class);

        config([
            'nexusdav.env_set' => ['NEXUSDAV_SHARING'],
            'nexusdav.features.sharing' => false,
        ]);
        $settings->save(['sharing' => true]);

        $this->assertTrue($settings->isLocked('sharing'));
        $this->assertFalse($settings->get('sharing'));
        $this->assertSame(0, DB::table('settings')->where('key', 'sharing')->count());
        $this->dav('PROPFIND', $url, 'bob', $this->bobToken, ['Depth' => '0'])->assertNotFound();

        config(['nexusdav.features.sharing' => true]);
        $settings->apply();
        $settings->save(['sharing' => false]);
        $this->assertTrue($settings->get('sharing'));
        $this->dav('PROPFIND', $url, 'bob', $this->bobToken, ['Depth' => '0'])->assertStatus(207);
    }

    public function test_a_plain_sync_token_is_refused_on_a_share_and_kept_for_the_owner(): void
    {
        $this->putItem($this->ada, $this->adaToken, '/dav/calendars/ada/default/meet.ics', $this->item('meet', 'VEVENT', 'Meet'));
        $share = $this->share(SharingService::READ);
        $token = $this->syncTokenOf($this->shareUrl($share), 'bob', $this->bobToken);
        $number = substr($token, strlen(self::PREFIX));
        $this->assertSame(1, preg_match('/^[1-9][0-9]*-([1-9][0-9]*)$/', $number, $matches), $token);
        $plain = self::PREFIX.$matches[1];

        $refused = $this->sync($this->shareUrl($share), 'bob', $this->bobToken, $plain);
        $this->assertSame(403, $refused->getStatusCode(), $this->body($refused));
        $this->assertStringContainsString('valid-sync-token', $this->body($refused));

        $this->sync('/dav/calendars/ada/default/', 'ada', $this->adaToken, $plain)->assertStatus(207);
        $this->sync($this->shareUrl($share), 'bob', $this->bobToken, $token)->assertStatus(207);
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

    private function share(int $access): CalendarInstance
    {
        return $this->shareWith($this->bob, $access);
    }

    private function shareWith(User $sharee, int $access): CalendarInstance
    {
        $calendar = CalendarInstance::query()->where('principaluri', 'principals/ada')->where('uri', 'default')->where('access', 1)->firstOrFail();
        $share = app(SharingService::class)->share($calendar, $sharee, $access);
        $this->assertInstanceOf(CalendarInstance::class, $share);

        return $share;
    }

    private function shareUrl(CalendarInstance $share): string
    {
        $username = str_starts_with((string) $share->principaluri, 'principals/')
            ? substr((string) $share->principaluri, strlen('principals/'))
            : (string) $share->principaluri;

        return '/dav/calendars/'.$username.'/'.$share->uri.'/';
    }

    /**
     * @return array{synctoken: int, changes: int}
     */
    private function tokenState(): array
    {
        $id = (int) CalendarInstance::query()->where('principaluri', 'principals/ada')->where('access', 1)->value('calendarid');

        return [
            'synctoken' => (int) DB::table('calendars')->where('id', $id)->value('synctoken'),
            'changes' => DB::table('calendarchanges')->where('calendarid', $id)->count(),
        ];
    }

    private function putItem(User $user, string $token, string $url, string $body): TestResponse
    {
        return $this->dav('PUT', $url, $user->username, $token, ['Content-Type' => 'text/calendar'], $body)->assertCreated();
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function dav(string $method, string $uri, string $username, string $password, array $headers = [], ?string $body = null): TestResponse
    {
        $server = ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode($username.':'.$password)];

        foreach ($headers as $name => $value) {
            $server[$name === 'Content-Type' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call($method, $uri, [], [], [], $server, $body);
    }

    private function sync(string $url, string $username, string $password, ?string $token = null): TestResponse
    {
        $token = $token === null ? '' : htmlspecialchars($token, ENT_XML1);

        return $this->dav('REPORT', $url, $username, $password, ['Depth' => '0', 'Content-Type' => 'application/xml'],
            '<?xml version="1.0"?><d:sync-collection xmlns:d="DAV:"><d:sync-token>'.$token.'</d:sync-token><d:sync-level>1</d:sync-level><d:prop><d:getetag/></d:prop></d:sync-collection>');
    }

    private function syncToken(TestResponse $response): string
    {
        $body = $this->body($response);
        $this->assertSame(1, preg_match('#<[^>]*sync-token>([^<]+)</[^>]*sync-token>#', $body, $matches), $body);

        return html_entity_decode($matches[1], ENT_XML1);
    }

    private function syncTokenOf(string $url, string $username, string $password): string
    {
        $body = $this->body($this->dav('PROPFIND', $url, $username, $password, ['Depth' => '0', 'Content-Type' => 'application/xml'], $this->propfind('<d:sync-token/>'))->assertStatus(207));
        $this->assertSame(1, preg_match('#<[^>]*sync-token>([^<]+)</[^>]*sync-token>#', $body, $matches), $body);

        return html_entity_decode($matches[1], ENT_XML1);
    }

    private function ctag(string $url, string $username, string $password): string
    {
        $body = $this->body($this->dav('PROPFIND', $url, $username, $password, ['Depth' => '0', 'Content-Type' => 'application/xml'],
            '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/"><d:prop><cs:getctag/></d:prop></d:propfind>')->assertStatus(207));
        $this->assertSame(1, preg_match('#getctag>([^<]+)</#', $body, $matches), $body);

        return html_entity_decode($matches[1], ENT_XML1);
    }

    /**
     * Privilege names from every current-user-privilege-set in the body.
     *
     * @return list<string>
     */
    private function privileges(string $body): array
    {
        preg_match_all('#<([A-Za-z0-9]+):privilege>\s*<\1:([A-Za-z0-9-]+)\s*/>#', $body, $matches);

        return $matches[2];
    }

    /**
     * Properties sabre answered 200 for. A 404 propstat still names them.
     */
    private function found(string $body): string
    {
        preg_match_all('#<[^>]*propstat>(?:(?!</[^>]*propstat>).)*<[^>]*status>[^<]*200[^<]*</[^>]*status>#s', $body, $matches);

        return implode("\n", $matches[0]);
    }

    private function body(TestResponse $response): string
    {
        return $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : (string) $response->getContent();
    }

    private function propfind(string $properties): string
    {
        return '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:cs="http://calendarserver.org/ns/"><d:prop>'.$properties.'</d:prop></d:propfind>';
    }

    private function calendarProps(): string
    {
        return $this->propfind('<d:displayname/><d:owner/><d:sync-token/><c:supported-calendar-component-set/>');
    }

    private function sharingProps(): string
    {
        return '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/">'
            .'<d:prop><d:resourcetype/><d:owner/><d:invite/><d:share-access/><d:share-resource-uri/>'
            .'<cs:invite/><cs:allowed-sharing-modes/><cs:shared-url/></d:prop></d:propfind>';
    }

    private function calendarQuery(): string
    {
        return '<?xml version="1.0"?><c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/><c:calendar-data/></d:prop>'
            .'<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT"/></c:comp-filter></c:filter></c:calendar-query>';
    }

    private function multiget(string $href): string
    {
        return '<?xml version="1.0"?><c:calendar-multiget xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/><c:calendar-data/></d:prop><d:href>'.$href.'</d:href></c:calendar-multiget>';
    }

    private function rename(): string
    {
        return '<?xml version="1.0"?><d:propertyupdate xmlns:d="DAV:" xmlns:a="http://apple.com/ns/ical/"><d:set><d:prop>'
            .'<d:displayname>Mine</d:displayname><a:calendar-color>#FF00AA</a:calendar-color><a:calendar-order>4</a:calendar-order>'
            .'</d:prop></d:set></d:propertyupdate>';
    }

    private function deadProperty(string $value): string
    {
        return '<?xml version="1.0"?><d:propertyupdate xmlns:d="DAV:" xmlns:x="urn:example"><d:set><d:prop><x:note>'.$value.'</x:note></d:prop></d:set></d:propertyupdate>';
    }

    private function lockInfo(): string
    {
        return '<?xml version="1.0"?><d:lockinfo xmlns:d="DAV:"><d:lockscope><d:exclusive/></d:lockscope><d:locktype><d:write/></d:locktype><d:owner>bob</d:owner></d:lockinfo>';
    }

    private function sharePost(): string
    {
        return '<?xml version="1.0"?><cs:share xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/"><cs:set><d:href>mailto:carol@example.com</d:href><cs:read/></cs:set></cs:share>';
    }

    private function inviteReply(): string
    {
        return '<?xml version="1.0"?><cs:invite-reply xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/"><d:href>mailto:bob@example.com</d:href><cs:invite-accepted/></cs:invite-reply>';
    }

    private function shareResource(): string
    {
        return '<?xml version="1.0"?><d:share-resource xmlns:d="DAV:"><d:sharee><d:href>mailto:carol@example.com</d:href><d:share-access><d:read/></d:share-access></d:sharee></d:share-resource>';
    }

    private function item(string $uid, string $component, string $summary = 'Item'): string
    {
        $dates = $component === 'VTODO' ? 'DUE:20261102T130000Z' : 'DTSTART:20261102T130000Z';

        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//NexusDAV//test//EN\r\nBEGIN:$component\r\nUID:$uid\r\nDTSTAMP:20260101T000000Z\r\n$dates\r\nSUMMARY:$summary\r\nEND:$component\r\nEND:VCALENDAR\r\n";
    }
}
