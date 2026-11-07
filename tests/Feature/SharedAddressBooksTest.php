<?php

namespace Tests\Feature;

use App\Models\AddressBook;
use App\Models\AddressBookInstance;
use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\Dav\SharingService;
use App\Services\SettingsService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * PR 24: a shared address book in the sharee's home, read only, with the
 * owner's cards and sync token.
 */
class SharedAddressBooksTest extends TestCase
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

    public function test_a_shared_address_book_is_listed_and_read_with_the_owners_cards_and_token(): void
    {
        $this->putCard('/dav/addressbooks/ada/default/zed.vcf', 'zed', 'Zed Zebra');
        $share = $this->share(SharingService::READ);
        $url = $this->shareUrl($share);

        $home = $this->body($this->dav('PROPFIND', '/dav/addressbooks/bob/', 'bob', $this->bobToken, ['Depth' => '1'], $this->propfind('<d:displayname/><d:resourcetype/>'))->assertStatus(207));
        $this->assertStringContainsString('/dav/addressbooks/bob/default/', $home);
        $this->assertStringContainsString('/dav/addressbooks/bob/'.$share->uri.'/', $home);
        $this->assertStringContainsString('Contacts (Ada Lovelace)', $home);

        $props = $this->body($this->dav('PROPFIND', $url, 'bob', $this->bobToken, ['Depth' => '0'], $this->propfind('<d:displayname/><d:owner/><d:sync-token/><d:resourcetype/>'))->assertStatus(207));
        $this->assertStringContainsString('addressbook', $props);
        $this->assertSame(1, preg_match('#<[^>]*owner>(.*?)</[^>]*owner>#s', $props, $owner), $props);
        $this->assertStringContainsString('/dav/principals/bob/', $owner[1]);
        $this->assertStringNotContainsString('principals/ada', $owner[1]);

        $this->assertSame(
            $this->syncTokenOf('/dav/addressbooks/ada/default/', 'ada', $this->adaToken),
            $this->syncTokenOf($url, 'bob', $this->bobToken),
        );
        $this->assertSame(
            $this->ctag('/dav/addressbooks/ada/default/', 'ada', $this->adaToken),
            $this->ctag($url, 'bob', $this->bobToken),
        );

        $this->assertStringContainsString('FN:Zed Zebra', $this->body($this->dav('GET', $url.'zed.vcf', 'bob', $this->bobToken)->assertOk()));

        $this->dav('HEAD', $url.'zed.vcf', 'bob', $this->bobToken)->assertOk();
        $this->dav('HEAD', '/dav/addressbooks/ada/default/zed.vcf', 'ada', $this->adaToken)->assertOk();

        $list = $this->body($this->dav('PROPFIND', $url, 'bob', $this->bobToken, ['Depth' => '1'], $this->propfind('<d:getetag/>'))->assertStatus(207));
        $this->assertStringContainsString($url.'zed.vcf', $list);

        $query = $this->body($this->dav('REPORT', $url, 'bob', $this->bobToken, ['Depth' => '1', 'Content-Type' => 'application/xml'], $this->cardQuery('Zed'))->assertStatus(207));
        $this->assertStringContainsString('zed.vcf', $query);
        $this->assertStringContainsString('FN:Zed Zebra', $query);
        $none = $this->body($this->dav('REPORT', $url, 'bob', $this->bobToken, ['Depth' => '1', 'Content-Type' => 'application/xml'], $this->cardQuery('Nobody'))->assertStatus(207));
        $this->assertStringNotContainsString('zed.vcf', $none);

        $multi = $this->body($this->dav('REPORT', $url, 'bob', $this->bobToken, ['Depth' => '1', 'Content-Type' => 'application/xml'], $this->multiget($url.'zed.vcf'))->assertStatus(207));
        $this->assertStringContainsString('FN:Zed Zebra', $multi);

        $homeSync = $this->body($this->sync('/dav/addressbooks/bob/', 'bob', $this->bobToken)->assertStatus(207));
        $this->assertStringContainsString('/dav/addressbooks/bob/'.$share->uri, $homeSync);
    }

    public function test_a_read_only_share_refuses_writes_and_lists_read_privileges(): void
    {
        $this->putCard('/dav/addressbooks/ada/default/zed.vcf', 'zed', 'Zed Zebra');
        $share = $this->share(SharingService::READ);
        $url = $this->shareUrl($share);
        $before = $this->ownerState();

        $options = $this->dav('OPTIONS', $url, 'bob', $this->bobToken)->assertOk();
        $this->assertStringNotContainsString('sharing', (string) $options->headers->get('DAV'));

        $collection = $this->privileges($this->body($this->dav('PROPFIND', $url, 'bob', $this->bobToken, ['Depth' => '0'], $this->propfind('<d:current-user-privilege-set/><d:acl/>'))->assertStatus(207)));
        $this->assertContains('read', $collection);
        $this->assertContains('write-properties', $collection);
        foreach (['all', 'write', 'write-content', 'bind', 'unbind', 'share'] as $privilege) {
            $this->assertNotContains($privilege, $collection);
        }

        $card = $this->privileges($this->body($this->dav('PROPFIND', $url.'zed.vcf', 'bob', $this->bobToken, ['Depth' => '0'], $this->propfind('<d:current-user-privilege-set/>'))->assertStatus(207)));
        $this->assertContains('read', $card);
        foreach (['all', 'write', 'write-properties', 'write-content', 'bind', 'unbind'] as $privilege) {
            $this->assertNotContains($privilege, $card);
        }

        $acl = $this->body($this->dav('PROPFIND', $url, 'bob', $this->bobToken, ['Depth' => '0'], $this->propfind('<d:acl/>'))->assertStatus(207));
        $this->assertStringNotContainsString('authenticated', $acl);

        $this->dav('PUT', $url.'new.vcf', 'bob', $this->bobToken, ['Content-Type' => 'text/vcard'], $this->card('new', 'New'))->assertForbidden();
        $etag = (string) $this->dav('GET', $url.'zed.vcf', 'bob', $this->bobToken)->assertOk()->headers->get('ETag');
        $this->dav('PUT', $url.'zed.vcf', 'bob', $this->bobToken, ['Content-Type' => 'text/vcard', 'If-Match' => $etag], $this->card('zed', 'Changed'))->assertForbidden();
        $this->dav('DELETE', $url.'zed.vcf', 'bob', $this->bobToken)->assertForbidden();
        $this->dav('PROPPATCH', $url.'zed.vcf', 'bob', $this->bobToken, ['Content-Type' => 'application/xml'], $this->rename())->assertForbidden();
        $this->dav('MOVE', $url.'zed.vcf', 'bob', $this->bobToken, [
            'Destination' => 'http://localhost/dav/addressbooks/bob/default/moved.vcf',
            'Overwrite' => 'F',
        ])->assertForbidden();
        $this->dav('LOCK', $url.'zed.vcf', 'bob', $this->bobToken, ['Content-Type' => 'application/xml'], $this->lockInfo())->assertForbidden();
        $this->dav('LOCK', $url, 'bob', $this->bobToken, ['Content-Type' => 'application/xml'], $this->lockInfo())->assertForbidden();
        $this->dav('MKCOL', $url.'inner/', 'bob', $this->bobToken)->assertForbidden();

        $this->assertSame($before, $this->ownerState());
        $this->assertSame(1, DB::table('addressbooks')->where('principaluri', 'principals/ada')->count());
        $this->dav('GET', '/dav/addressbooks/ada/default/zed.vcf', 'ada', $this->adaToken)->assertOk();
        $this->assertStringContainsString('FN:Zed Zebra', $this->body($this->dav('GET', '/dav/addressbooks/ada/default/zed.vcf', 'ada', $this->adaToken)->assertOk()));

        // A card copied out of a read-only share is the sharee's own copy.
        $this->dav('COPY', $url.'zed.vcf', 'bob', $this->bobToken, [
            'Destination' => 'http://localhost/dav/addressbooks/bob/default/copied.vcf',
            'Overwrite' => 'F',
        ])->assertSuccessful();
        $this->assertSame($before, $this->ownerState());
    }

    public function test_a_read_write_share_lists_write_privileges_and_never_all(): void
    {
        $this->putCard('/dav/addressbooks/ada/default/zed.vcf', 'zed', 'Zed Zebra');
        $url = $this->shareUrl($this->share(SharingService::READ_WRITE));

        $collection = $this->privileges($this->body($this->dav('PROPFIND', $url, 'bob', $this->bobToken, ['Depth' => '0'], $this->propfind('<d:current-user-privilege-set/>'))->assertStatus(207)));
        foreach (['read', 'write', 'write-properties', 'write-content', 'bind', 'unbind'] as $privilege) {
            $this->assertContains($privilege, $collection);
        }
        foreach (['all', 'share'] as $privilege) {
            $this->assertNotContains($privilege, $collection);
        }

        $card = $this->privileges($this->body($this->dav('PROPFIND', $url.'zed.vcf', 'bob', $this->bobToken, ['Depth' => '0'], $this->propfind('<d:current-user-privilege-set/>'))->assertStatus(207)));
        foreach (['read', 'write', 'write-content'] as $privilege) {
            $this->assertContains($privilege, $card);
        }
        $this->assertNotContains('all', $card);
    }

    public function test_a_read_write_sharee_creates_changes_and_deletes_contacts_in_the_owners_book(): void
    {
        $this->putCard('/dav/addressbooks/ada/default/zed.vcf', 'zed', 'Zed Zebra');
        $bobShare = $this->share(SharingService::READ_WRITE);
        $carolShare = app(SharingService::class)->share($this->book(), $this->carol, SharingService::READ);
        $bobUrl = $this->shareUrl($bobShare);
        $carolUrl = '/dav/addressbooks/carol/'.$carolShare->uri.'/';

        $ownerToken = $this->syncToken($this->sync('/dav/addressbooks/ada/default/', 'ada', $this->adaToken));
        $carolSync = $this->syncToken($this->sync($carolUrl, 'carol', $this->carolToken));
        $before = $this->ownerState();

        $created = $this->dav('PUT', $bobUrl.'new.vcf', 'bob', $this->bobToken, ['Content-Type' => 'text/vcard', 'If-None-Match' => '*'], $this->card('new', 'New Person'));
        $this->assertSame(201, $created->getStatusCode(), $this->body($created));
        $this->assertStringContainsString('FN:New Person', $this->body($this->dav('GET', '/dav/addressbooks/ada/default/new.vcf', 'ada', $this->adaToken)->assertOk()));
        $this->assertSame(1, DB::table('cards')->where('addressbookid', $this->book()->getKey())->where('uri', 'new.vcf')->count());

        $after = $this->ownerState();
        $this->assertGreaterThan($before['synctoken'], $after['synctoken']);
        $this->assertGreaterThan($before['changes'], $after['changes']);

        $ownerDelta = $this->body($this->sync('/dav/addressbooks/ada/default/', 'ada', $this->adaToken, $ownerToken)->assertStatus(207));
        $this->assertStringContainsString('new.vcf', $ownerDelta);
        $carolDelta = $this->body($this->sync($carolUrl, 'carol', $this->carolToken, $carolSync)->assertStatus(207));
        $this->assertStringContainsString('new.vcf', $carolDelta);
        $this->assertStringNotContainsString('zed.vcf', $carolDelta);

        $etag = (string) $this->dav('GET', $bobUrl.'zed.vcf', 'bob', $this->bobToken)->assertOk()->headers->get('ETag');
        $this->dav('PUT', $bobUrl.'zed.vcf', 'bob', $this->bobToken, ['Content-Type' => 'text/vcard', 'If-Match' => $etag], $this->card('zed', 'Zed Changed'))->assertNoContent();
        $this->assertStringContainsString('FN:Zed Changed', $this->body($this->dav('GET', '/dav/addressbooks/ada/default/zed.vcf', 'ada', $this->adaToken)->assertOk()));
        $stale = $this->dav('PUT', $bobUrl.'zed.vcf', 'bob', $this->bobToken, ['Content-Type' => 'text/vcard', 'If-Match' => $etag], $this->card('zed', 'Stale'));
        $this->assertSame(412, $stale->getStatusCode());

        $this->dav('DELETE', $bobUrl.'new.vcf', 'bob', $this->bobToken)->assertNoContent();
        $this->dav('GET', '/dav/addressbooks/ada/default/new.vcf', 'ada', $this->adaToken)->assertNotFound();
        $this->dav('GET', $carolUrl.'new.vcf', 'carol', $this->carolToken)->assertNotFound();

        // And the other way: an owner change reaches the sharee.
        $bobSync = $this->syncToken($this->sync($bobUrl, 'bob', $this->bobToken));
        $this->putCard('/dav/addressbooks/ada/default/again.vcf', 'again', 'Again');
        $this->assertStringContainsString($bobUrl.'again.vcf', $this->body($this->sync($bobUrl, 'bob', $this->bobToken, $bobSync)->assertStatus(207)));

        // A sharee cannot write as the owner or into another book.
        $this->dav('PUT', '/dav/addressbooks/ada/default/evil.vcf', 'bob', $this->bobToken, ['Content-Type' => 'text/vcard'], $this->card('evil', 'Evil'))->assertNotFound();
        $this->assertSame(0, DB::table('cards')->where('uri', 'evil.vcf')->count());
    }

    public function test_a_read_only_sharee_still_cannot_change_a_card_after_the_access_is_lowered(): void
    {
        $this->putCard('/dav/addressbooks/ada/default/zed.vcf', 'zed', 'Zed Zebra');
        $share = $this->share(SharingService::READ_WRITE);
        $url = $this->shareUrl($share);
        $this->dav('PUT', $url.'one.vcf', 'bob', $this->bobToken, ['Content-Type' => 'text/vcard'], $this->card('one', 'One'))->assertCreated();

        app(SharingService::class)->changeAccess($this->book(), $this->bob, SharingService::READ);

        $this->dav('PUT', $url.'two.vcf', 'bob', $this->bobToken, ['Content-Type' => 'text/vcard'], $this->card('two', 'Two'))->assertForbidden();
        $this->dav('DELETE', $url.'one.vcf', 'bob', $this->bobToken)->assertForbidden();
        $this->assertSame(1, DB::table('cards')->where('uri', 'one.vcf')->count());
    }

    public function test_the_sharees_name_and_description_stay_on_their_row(): void
    {
        $share = $this->share(SharingService::READ);
        $url = $this->shareUrl($share);
        $owner = DB::table('addressbooks')->where('id', $this->book()->getKey())->first(['displayname', 'description']);
        $before = $this->ownerState();
        $ownerToken = $this->syncTokenOf('/dav/addressbooks/ada/default/', 'ada', $this->adaToken);

        $patched = $this->dav('PROPPATCH', $url, 'bob', $this->bobToken, ['Content-Type' => 'application/xml'], $this->rename());
        $this->assertSame(207, $patched->getStatusCode(), $this->body($patched));
        $this->assertStringNotContainsString('403', $this->body($patched));

        $row = DB::table('addressbookinstances')->where('id', $share->getKey())->first(['displayname', 'description']);
        $this->assertSame('Mine', $row?->displayname);
        $this->assertSame('My people', $row?->description);
        $this->assertEquals($owner, DB::table('addressbooks')->where('id', $this->book()->getKey())->first(['displayname', 'description']));
        $this->assertSame($before, $this->ownerState());
        $this->assertSame($ownerToken, $this->syncTokenOf('/dav/addressbooks/ada/default/', 'ada', $this->adaToken));

        $props = $this->body($this->dav('PROPFIND', $url, 'bob', $this->bobToken, ['Depth' => '0'], $this->propfind('<d:displayname/>'))->assertStatus(207));
        $this->assertStringContainsString('Mine', $props);
        $ownerProps = $this->body($this->dav('PROPFIND', '/dav/addressbooks/ada/default/', 'ada', $this->adaToken, ['Depth' => '0'], $this->propfind('<d:displayname/>'))->assertStatus(207));
        $this->assertStringNotContainsString('Mine', $ownerProps);

        $long = $this->dav('PROPPATCH', $url, 'bob', $this->bobToken, ['Content-Type' => 'application/xml'], $this->rename(str_repeat('x', 300)));
        $this->assertStringContainsString('403', $this->body($long));
        $this->assertSame('Mine', DB::table('addressbookinstances')->where('id', $share->getKey())->value('displayname'));

        $this->dav('PROPPATCH', $url, 'bob', $this->bobToken, ['Content-Type' => 'application/xml'], $this->deadProperty('mine'))->assertStatus(207);
        $this->assertStringNotContainsString('mine', $this->body($this->dav('PROPFIND', '/dav/addressbooks/ada/default/', 'ada', $this->adaToken, ['Depth' => '0'], '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:x="urn:example"><d:prop><x:note/></d:prop></d:propfind>')->assertStatus(207)));
    }

    public function test_deleting_the_shared_book_removes_only_the_share(): void
    {
        $this->putCard('/dav/addressbooks/ada/default/zed.vcf', 'zed', 'Zed Zebra');

        foreach ([SharingService::READ, SharingService::READ_WRITE] as $access) {
            $share = $this->share($access);
            $url = $this->shareUrl($share);
            $path = 'addressbooks/bob/'.$share->uri;
            $this->dav('PROPPATCH', $url, 'bob', $this->bobToken, ['Content-Type' => 'application/xml'], $this->deadProperty('mine'))->assertStatus(207);
            $this->assertGreaterThan(0, DB::table('propertystorage')->where('path', $path)->count());
            $home = $this->syncToken($this->sync('/dav/addressbooks/bob/', 'bob', $this->bobToken));
            $before = $this->ownerState();

            $this->dav('DELETE', $url, 'bob', $this->bobToken)->assertNoContent();

            $this->assertSame(0, DB::table('addressbookinstances')->where('principaluri', 'principals/bob')->count());
            $this->assertSame(1, DB::table('addressbooks')->where('principaluri', 'principals/ada')->count());
            $this->assertSame($before, $this->ownerState());
            $this->assertSame(0, DB::table('propertystorage')->where('path', $path)->orWhere('path', 'like', $path.'/%')->count());
            $this->dav('GET', '/dav/addressbooks/ada/default/zed.vcf', 'ada', $this->adaToken)->assertOk();
            $this->dav('PROPFIND', $url, 'bob', $this->bobToken, ['Depth' => '0'])->assertNotFound();

            $removed = $this->body($this->sync('/dav/addressbooks/bob/', 'bob', $this->bobToken, $home)->assertStatus(207));
            $this->assertStringContainsString('/dav/addressbooks/bob/'.$share->uri, $removed);
            $this->assertStringContainsString('404', $removed);
        }
    }

    public function test_the_default_address_book_of_the_sharee_is_not_touched_by_leaving_a_share(): void
    {
        $this->putCard('/dav/addressbooks/ada/default/zed.vcf', 'zed', 'Zed Zebra');
        $this->dav('PUT', '/dav/addressbooks/bob/default/mine.vcf', 'bob', $this->bobToken, ['Content-Type' => 'text/vcard'], $this->card('mine', 'Mine'))->assertCreated();
        $share = $this->share(SharingService::READ_WRITE);

        $this->dav('DELETE', $this->shareUrl($share), 'bob', $this->bobToken)->assertNoContent();

        $this->dav('GET', '/dav/addressbooks/bob/default/mine.vcf', 'bob', $this->bobToken)->assertOk();
        $this->assertSame(1, DB::table('addressbooks')->where('principaluri', 'principals/bob')->count());
    }

    public function test_an_owner_change_reaches_the_sharee_as_one_incremental_change(): void
    {
        $this->putCard('/dav/addressbooks/ada/default/one.vcf', 'one', 'One');
        $this->putCard('/dav/addressbooks/ada/default/two.vcf', 'two', 'Two');
        $share = $this->share(SharingService::READ);
        $url = $this->shareUrl($share);

        $first = $this->sync($url, 'bob', $this->bobToken)->assertStatus(207);
        $initial = $this->body($first);
        $this->assertStringContainsString('one.vcf', $initial);
        $this->assertStringContainsString('two.vcf', $initial);
        $token = $this->syncToken($first);
        $again = $this->body($this->sync($url, 'bob', $this->bobToken, $token)->assertStatus(207));
        $this->assertStringNotContainsString('.vcf', $again);

        $this->putCard('/dav/addressbooks/ada/default/three.vcf', 'three', 'Three');
        $this->dav('DELETE', '/dav/addressbooks/ada/default/one.vcf', 'ada', $this->adaToken)->assertNoContent();

        $delta = $this->body($this->sync($url, 'bob', $this->bobToken, $token)->assertStatus(207));
        $this->assertStringContainsString($url.'three.vcf', $delta);
        $this->assertStringContainsString($url.'one.vcf', $delta);
        $this->assertStringNotContainsString('two.vcf', $delta);
        $this->assertStringContainsString('404', $delta);

        $ownerDelta = $this->body($this->sync('/dav/addressbooks/ada/default/', 'ada', $this->adaToken, $token)->assertStatus(207));
        $this->assertStringContainsString('three.vcf', $ownerDelta);
    }

    public function test_a_plain_sync_token_is_refused_on_a_share_and_kept_for_the_owner(): void
    {
        $this->putCard('/dav/addressbooks/ada/default/one.vcf', 'one', 'One');
        $share = $this->share(SharingService::READ);
        $url = $this->shareUrl($share);
        $token = $this->syncTokenOf($url, 'bob', $this->bobToken);
        $this->assertSame(1, preg_match('/^[1-9][0-9]*-([1-9][0-9]*)$/', substr($token, strlen(self::PREFIX)), $matches), $token);
        $plain = self::PREFIX.$matches[1];

        $refused = $this->sync($url, 'bob', $this->bobToken, $plain);
        $this->assertSame(403, $refused->getStatusCode(), $this->body($refused));
        $this->assertStringContainsString('valid-sync-token', $this->body($refused));

        $this->sync('/dav/addressbooks/ada/default/', 'ada', $this->adaToken, $plain)->assertStatus(207);
        $this->sync($url, 'bob', $this->bobToken, $token)->assertStatus(207);

        $other = $this->sync($url, 'bob', $this->bobToken, self::PREFIX.'999999-1');
        $this->assertSame(403, $other->getStatusCode());
    }

    public function test_the_home_sync_sees_a_share_added_and_removed(): void
    {
        $home = $this->syncToken($this->sync('/dav/addressbooks/bob/', 'bob', $this->bobToken)->assertStatus(207));
        $share = $this->share(SharingService::READ);

        $added = $this->body($this->sync('/dav/addressbooks/bob/', 'bob', $this->bobToken, $home)->assertStatus(207));
        $this->assertStringContainsString('/dav/addressbooks/bob/'.$share->uri, $added);
        $home = $this->syncToken($this->sync('/dav/addressbooks/bob/', 'bob', $this->bobToken, $home));

        $this->putCard('/dav/addressbooks/ada/default/zed.vcf', 'zed', 'Zed');
        $changed = $this->body($this->sync('/dav/addressbooks/bob/', 'bob', $this->bobToken, $home)->assertStatus(207));
        $this->assertStringContainsString('/dav/addressbooks/bob/'.$share->uri, $changed);
        $this->assertStringNotContainsString('/dav/addressbooks/bob/default/', $changed);
        $home = $this->syncToken($this->sync('/dav/addressbooks/bob/', 'bob', $this->bobToken, $home));

        app(SharingService::class)->unshare($this->book(), $this->bob);
        $removed = $this->body($this->sync('/dav/addressbooks/bob/', 'bob', $this->bobToken, $home)->assertStatus(207));
        $this->assertStringContainsString('/dav/addressbooks/bob/'.$share->uri, $removed);
        $this->assertStringContainsString('404', $removed);
        $this->dav('PROPFIND', $this->shareUrl($share), 'bob', $this->bobToken, ['Depth' => '0'])->assertNotFound();
    }

    public function test_the_owner_stays_hidden_and_a_third_user_sees_nothing(): void
    {
        $this->putCard('/dav/addressbooks/ada/default/zed.vcf', 'zed', 'Zed');
        $share = $this->share(SharingService::READ);
        $url = $this->shareUrl($share);

        foreach (['/dav/principals/ada/', '/dav/addressbooks/ada/', '/dav/addressbooks/ada/default/', '/dav/calendars/ada/'] as $path) {
            $this->dav('PROPFIND', $path, 'bob', $this->bobToken, ['Depth' => '0'])->assertNotFound();
        }

        $this->dav('PROPFIND', $url, 'carol', $this->carolToken, ['Depth' => '1'])->assertNotFound();
        $this->dav('PROPFIND', '/dav/addressbooks/ada/default/', 'carol', $this->carolToken, ['Depth' => '1'])->assertNotFound();
        $this->dav('GET', $url.'zed.vcf', 'carol', $this->carolToken)->assertNotFound();
        $this->dav('REPORT', $url, 'carol', $this->carolToken, ['Depth' => '0', 'Content-Type' => 'application/xml'], $this->cardQuery('Zed'))->assertNotFound();

        $carolHome = $this->body($this->dav('PROPFIND', '/dav/addressbooks/carol/', 'carol', $this->carolToken, ['Depth' => '1'])->assertStatus(207));
        $this->assertStringNotContainsString($share->uri, $carolHome);
        $this->assertStringNotContainsString('/dav/addressbooks/ada', $carolHome);
    }

    public function test_a_disabled_owner_does_not_hide_the_share(): void
    {
        $this->putCard('/dav/addressbooks/ada/default/zed.vcf', 'zed', 'Zed');
        $share = $this->share(SharingService::READ);
        $this->ada->forceFill(['is_active' => false])->save();

        $this->dav('GET', '/dav/addressbooks/ada/default/zed.vcf', 'ada', $this->adaToken)->assertUnauthorized();
        $this->dav('GET', $this->shareUrl($share).'zed.vcf', 'bob', $this->bobToken)->assertOk();
    }

    public function test_the_sharing_and_carddav_switches_hide_a_shared_address_book(): void
    {
        $share = $this->share(SharingService::READ);
        $url = $this->shareUrl($share);
        $this->dav('PROPFIND', $url, 'bob', $this->bobToken, ['Depth' => '0'])->assertStatus(207);

        app(SettingsService::class)->save(['sharing' => false]);
        $this->dav('PROPFIND', $url, 'bob', $this->bobToken, ['Depth' => '0'])->assertNotFound();
        $this->dav('GET', $url, 'bob', $this->bobToken)->assertNotFound();
        $home = $this->body($this->dav('PROPFIND', '/dav/addressbooks/bob/', 'bob', $this->bobToken, ['Depth' => '1'])->assertStatus(207));
        $this->assertStringNotContainsString('/dav/addressbooks/bob/'.$share->uri, $home);
        $this->assertStringContainsString('/dav/addressbooks/bob/default/', $home);
        $this->assertSame(1, AddressBookInstance::query()->count());

        app(SettingsService::class)->save(['sharing' => true]);
        $this->dav('PROPFIND', $url, 'bob', $this->bobToken, ['Depth' => '0'])->assertStatus(207);

        app(SettingsService::class)->save(['carddav' => false]);
        $this->dav('PROPFIND', $url, 'bob', $this->bobToken, ['Depth' => '0'])->assertForbidden();
        $this->assertSame(1, AddressBookInstance::query()->count());
    }

    public function test_the_owners_own_book_keeps_working_beside_a_share(): void
    {
        $share = $this->share(SharingService::READ);

        $this->assertSame('default-ada', $share->uri);
        $this->dav('PROPPATCH', '/dav/addressbooks/ada/default/', 'ada', $this->adaToken, ['Content-Type' => 'application/xml'], $this->rename())->assertStatus(207);
        $this->putCard('/dav/addressbooks/ada/default/zed.vcf', 'zed', 'Zed');
        $this->dav('DELETE', '/dav/addressbooks/ada/default/zed.vcf', 'ada', $this->adaToken)->assertNoContent();
        $this->dav('PROPFIND', '/dav/addressbooks/bob/default/', 'bob', $this->bobToken, ['Depth' => '0'])->assertStatus(207);
        $this->assertSame('Contacts (Ada Lovelace)', DB::table('addressbookinstances')->where('id', $share->getKey())->value('displayname'));
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

    private function book(): AddressBook
    {
        return AddressBook::query()->where('principaluri', 'principals/ada')->where('uri', 'default')->firstOrFail();
    }

    private function share(int $access): AddressBookInstance
    {
        $share = app(SharingService::class)->share($this->book(), $this->bob, $access);
        $this->assertInstanceOf(AddressBookInstance::class, $share);

        return $share;
    }

    private function shareUrl(AddressBookInstance $share): string
    {
        return '/dav/addressbooks/'.substr((string) $share->principaluri, strlen('principals/')).'/'.$share->uri.'/';
    }

    /**
     * @return array{synctoken: int, changes: int, cards: int}
     */
    private function ownerState(): array
    {
        $id = $this->book()->getKey();

        return [
            'synctoken' => (int) DB::table('addressbooks')->where('id', $id)->value('synctoken'),
            'changes' => DB::table('addressbookchanges')->where('addressbookid', $id)->count(),
            'cards' => DB::table('cards')->where('addressbookid', $id)->count(),
        ];
    }

    private function putCard(string $url, string $uid, string $name): TestResponse
    {
        return $this->dav('PUT', $url, 'ada', $this->adaToken, ['Content-Type' => 'text/vcard'], $this->card($uid, $name))->assertCreated();
    }

    private function card(string $uid, string $name): string
    {
        return "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:{$uid}\r\nFN:{$name}\r\nN:{$name};;;;\r\nEND:VCARD\r\n";
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
        return '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav" xmlns:cs="http://calendarserver.org/ns/"><d:prop>'.$properties.'</d:prop></d:propfind>';
    }

    private function cardQuery(string $name): string
    {
        return '<?xml version="1.0"?><card:addressbook-query xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav"><d:prop><d:getetag/><card:address-data/></d:prop>'
            .'<card:filter><card:prop-filter name="FN"><card:text-match collation="i;unicode-casemap" match-type="contains">'.$name.'</card:text-match></card:prop-filter></card:filter></card:addressbook-query>';
    }

    private function multiget(string $href): string
    {
        return '<?xml version="1.0"?><card:addressbook-multiget xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav"><d:prop><d:getetag/><card:address-data/></d:prop><d:href>'.$href.'</d:href></card:addressbook-multiget>';
    }

    private function rename(string $name = 'Mine'): string
    {
        return '<?xml version="1.0"?><d:propertyupdate xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav"><d:set><d:prop><d:displayname>'.$name.'</d:displayname><card:addressbook-description>My people</card:addressbook-description></d:prop></d:set></d:propertyupdate>';
    }

    private function deadProperty(string $value): string
    {
        return '<?xml version="1.0"?><d:propertyupdate xmlns:d="DAV:" xmlns:x="urn:example"><d:set><d:prop><x:note>'.$value.'</x:note></d:prop></d:set></d:propertyupdate>';
    }

    private function lockInfo(): string
    {
        return '<?xml version="1.0"?><d:lockinfo xmlns:d="DAV:"><d:lockscope><d:exclusive/></d:lockscope><d:locktype><d:write/></d:locktype><d:owner>bob</d:owner></d:lockinfo>';
    }
}
