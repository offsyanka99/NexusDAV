<?php

namespace Tests\Feature;

use App\Models\AddressBook;
use App\Models\AppPassword;
use App\Models\CalendarInstance;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\Dav\FileStorage;
use App\Services\Dav\SharingService;
use App\Services\Push\PushSubscriptions;
use App\Services\SettingsService;
use App\Services\UserService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\PushHelpers;
use Tests\TestCase;

/**
 * PR 35: push discovery and subscription registration
 * (docs/design/webdav-push.md).
 */
class PushRegistrationTest extends TestCase
{
    use PushHelpers, RefreshDatabase;

    private User $ada;

    private User $bob;

    private string $adaToken;

    private string $bobToken;

    protected function setUp(): void
    {
        parent::setUp();

        config(['nexusdav.features.push' => true, 'app.url' => 'https://dav.example.org']);
        $this->fakeDelivery();

        $this->ada = $this->user('ada');
        $this->bob = $this->user('bob');
        $this->adaToken = $this->token($this->ada);
        $this->bobToken = $this->token($this->bob);
    }

    public function test_push_settings_are_defined_and_push_is_off_by_default(): void
    {
        $definitions = SettingsService::DEFINITIONS;

        $this->assertSame(false, $definitions['push']['default']);
        $this->assertSame('NEXUSDAV_PUSH', $definitions['push']['env']);
        $this->assertSame(false, $definitions['push_files']['default']);
        $this->assertSame(100, $definitions['push_max_subscriptions']['default']);
        $this->assertSame(false, $definitions['push_allow_private']['default']);
        $this->assertSame('NEXUSDAV_PUSH_LOG_LEVEL', $definitions['push_log_level']['env']);
    }

    public function test_calendars_address_books_and_their_homes_advertise_push(): void
    {
        $dav = (string) $this->dav('OPTIONS', '/dav/calendars/ada/default/', 'ada', $this->adaToken)->assertOk()->headers->get('DAV');
        $this->assertStringContainsString('webdav-push', $dav);

        $calendar = $this->body($this->dav('PROPFIND', '/dav/calendars/ada/default/', 'ada', $this->adaToken, ['Depth' => '0'], $this->pushProps())->assertStatus(207));
        $this->assertMatchesRegularExpression('#<P:vapid-public-key type="p256ecdsa">B[A-Za-z0-9_-]{86}</P:vapid-public-key>#', $calendar);
        $this->assertMatchesRegularExpression('#<P:topic>[A-Za-z0-9_-]{22}</P:topic>#', $calendar);
        $this->assertStringContainsString('<P:content-update><d:depth>1</d:depth></P:content-update>', $calendar);
        $this->assertStringContainsString('<P:property-update><d:depth>0</d:depth></P:property-update>', $calendar);

        $book = $this->body($this->dav('PROPFIND', '/dav/addressbooks/ada/default/', 'ada', $this->adaToken, ['Depth' => '0'], $this->pushProps())->assertStatus(207));
        $this->assertStringContainsString('<P:content-update><d:depth>1</d:depth></P:content-update>', $book);
        $this->assertStringContainsString('<P:property-update><d:depth>0</d:depth></P:property-update>', $book);

        foreach (['/dav/calendars/ada/', '/dav/addressbooks/ada/'] as $home) {
            $body = $this->body($this->dav('PROPFIND', $home, 'ada', $this->adaToken, ['Depth' => '0'], $this->pushProps())->assertStatus(207));
            $this->assertStringContainsString('<P:content-update><d:depth>1</d:depth></P:content-update>', $body, $home);
            $this->assertStringNotContainsString('property-update', $body, $home);
        }

        // Each collection has its own topic; a depth-1 listing shows them.
        $topics = [
            $this->topicOf('/dav/calendars/ada/default/', 'ada', $this->adaToken),
            $this->topicOf('/dav/addressbooks/ada/default/', 'ada', $this->adaToken),
            $this->topicOf('/dav/calendars/ada/', 'ada', $this->adaToken),
            $this->topicOf('/dav/addressbooks/ada/', 'ada', $this->adaToken),
            $this->topicOf('/dav/calendars/bob/default/', 'bob', $this->bobToken),
        ];
        $this->assertCount(5, array_unique($topics));
        $listing = $this->body($this->dav('PROPFIND', '/dav/calendars/ada/', 'ada', $this->adaToken, ['Depth' => '1'], $this->pushProps())->assertStatus(207));
        $this->assertStringContainsString('<P:topic>'.$topics[0].'</P:topic>', $listing);

        // A principal and an event offer no push.
        $this->dav('PUT', '/dav/calendars/ada/default/a.ics', 'ada', $this->adaToken, ['Content-Type' => 'text/calendar'], $this->event('a'))->assertCreated();

        foreach (['/dav/principals/ada/', '/dav/calendars/ada/default/a.ics'] as $path) {
            $body = $this->body($this->dav('PROPFIND', $path, 'ada', $this->adaToken, ['Depth' => '0'], $this->pushProps())->assertStatus(207));
            $this->assertStringNotContainsString('<P:topic>', $body, $path);
            $this->assertStringNotContainsString('vapid-public-key', $body, $path);
        }
    }

    public function test_the_vapid_key_and_the_topic_key_are_made_once_and_stored_encrypted(): void
    {
        $first = $this->body($this->dav('PROPFIND', '/dav/calendars/ada/default/', 'ada', $this->adaToken, ['Depth' => '0'], $this->pushProps()));
        $this->assertSame(1, preg_match('#type="p256ecdsa">([^<]+)<#', $first, $match));
        $public = $match[1];

        $this->app->forgetScopedInstances();
        $second = $this->body($this->dav('PROPFIND', '/dav/calendars/bob/default/', 'bob', $this->bobToken, ['Depth' => '0'], $this->pushProps()));
        $this->assertStringContainsString($public, $second);

        $stored = DB::table('settings')->whereIn('key', ['push.vapid', 'push.topic_key'])->pluck('value', 'key');
        $this->assertCount(2, $stored);
        $this->assertStringNotContainsString($public, (string) $stored['push.vapid']);
        $vapid = json_decode(Crypt::decryptString((string) json_decode((string) $stored['push.vapid'], true)), true);
        $this->assertSame($public, $vapid['publicKey']);
        $this->assertNotEmpty($vapid['privateKey']);
    }

    public function test_nothing_is_advertised_and_registration_is_refused_when_push_is_off(): void
    {
        config(['nexusdav.features.push' => false]);

        $dav = (string) $this->dav('OPTIONS', '/dav/calendars/ada/default/', 'ada', $this->adaToken)->assertOk()->headers->get('DAV');
        $this->assertStringNotContainsString('webdav-push', $dav);

        $body = $this->body($this->dav('PROPFIND', '/dav/calendars/ada/default/', 'ada', $this->adaToken, ['Depth' => '0'], $this->pushProps())->assertStatus(207));
        $this->assertStringNotContainsString('<P:topic>', $body);
        $this->assertStringContainsString('404 Not Found', $body);

        $refused = $this->register('/dav/calendars/ada/default/', 'ada', $this->adaToken)->assertForbidden();
        $this->assertStringContainsString('push-not-available', $this->body($refused));
        $this->assertSame(0, PushSubscription::query()->count());
        $this->assertNull(DB::table('settings')->where('key', 'push.vapid')->value('value'));
    }

    public function test_existing_subscriptions_stay_when_push_is_switched_off(): void
    {
        $this->subscribe('/dav/calendars/ada/default/', 'ada', $this->adaToken);
        config(['nexusdav.features.push' => false]);

        $this->dav('PUT', '/dav/calendars/ada/default/a.ics', 'ada', $this->adaToken, ['Content-Type' => 'text/calendar'], $this->event('a'))->assertCreated();

        $this->assertSame(1, PushSubscription::query()->count());
        $this->assertSame(0, DB::table('push_outbox')->count());
    }

    public function test_a_shared_collection_has_one_topic_for_the_owner_and_every_sharee(): void
    {
        $calendar = CalendarInstance::query()->where('principaluri', 'principals/ada')->where('uri', 'default')->firstOrFail();
        $calendarShare = app(SharingService::class)->share($calendar, $this->bob, SharingService::READ);
        $book = AddressBook::query()->where('principaluri', 'principals/ada')->where('uri', 'default')->firstOrFail();
        $bookShare = app(SharingService::class)->share($book, $this->bob, SharingService::READ);

        $this->assertSame(
            $this->topicOf('/dav/calendars/ada/default/', 'ada', $this->adaToken),
            $this->topicOf('/dav/calendars/bob/'.$calendarShare->uri.'/', 'bob', $this->bobToken),
        );
        $this->assertSame(
            $this->topicOf('/dav/addressbooks/ada/default/', 'ada', $this->adaToken),
            $this->topicOf('/dav/addressbooks/bob/'.$bookShare->uri.'/', 'bob', $this->bobToken),
        );

        // A read-only sharee may subscribe; the row has the shared topic.
        $row = $this->subscribe('/dav/calendars/bob/'.$calendarShare->uri.'/', 'bob', $this->bobToken);
        $this->assertSame($this->topicOf('/dav/calendars/ada/default/', 'ada', $this->adaToken), $row->topic);
        $this->assertSame('calendars/bob/'.$calendarShare->uri, $row->path);
    }

    public function test_registration_answers_201_then_204_for_the_same_push_resource(): void
    {
        CarbonImmutable::setTestNow('2026-11-09 12:00:00');
        $resource = 'https://push.example.net/up/abc';

        $first = $this->register('/dav/calendars/ada/default/', 'ada', $this->adaToken, ['resource' => $resource]);
        $this->assertSame(201, $first->getStatusCode(), $this->body($first));
        $location = (string) $first->headers->get('Location');
        $this->assertMatchesRegularExpression('#^https://dav\.example\.org/dav/push/[A-Za-z0-9_-]{32}$#', $location);
        $this->assertSame('Thu, 12 Nov 2026 12:00:00 GMT', $first->headers->get('Expires'));
        $this->assertSame('', $this->body($first));

        $row = PushSubscription::query()->firstOrFail();
        $this->assertSame((int) $this->ada->getKey(), $row->user_id);
        $this->assertSame((int) AppPassword::query()->where('user_id', $this->ada->getKey())->value('id'), $row->app_password_id);
        $this->assertSame('calendars/ada/default', $row->path);
        $this->assertSame('1', $row->content_update_depth);
        $this->assertNull($row->property_update_depth);

        $again = $this->register('/dav/calendars/ada/default/', 'ada', $this->adaToken, [
            'resource' => $resource,
            'property' => '0',
            'expires' => 'Sat, 14 Nov 2026 08:30:00 GMT',
        ]);
        $this->assertSame(204, $again->getStatusCode(), $this->body($again));
        $this->assertSame($location, $again->headers->get('Location'));
        $this->assertSame('Sat, 14 Nov 2026 08:30:00 GMT', $again->headers->get('Expires'));
        $this->assertSame(1, PushSubscription::query()->count());
        $row->refresh();
        $this->assertSame('0', $row->property_update_depth);
        $this->assertSame(['{DAV:}displayname'], $row->properties);

        // Another push resource, or the same one on another collection, is a new registration.
        $this->register('/dav/calendars/ada/default/', 'ada', $this->adaToken)->assertCreated();
        $this->register('/dav/addressbooks/ada/default/', 'ada', $this->adaToken, ['resource' => $resource])->assertCreated();
        $this->assertSame(3, PushSubscription::query()->count());

        CarbonImmutable::setTestNow();
    }

    public function test_the_expiry_is_capped_at_seven_days_and_raised_to_one_hour(): void
    {
        CarbonImmutable::setTestNow('2026-11-09 12:00:00');
        $path = '/dav/calendars/ada/default/';

        $far = $this->register($path, 'ada', $this->adaToken, ['expires' => 'Wed, 09 Dec 2026 12:00:00 GMT'])->assertCreated();
        $this->assertSame('Mon, 16 Nov 2026 12:00:00 GMT', $far->headers->get('Expires'));

        $past = $this->register($path, 'ada', $this->adaToken, ['expires' => 'Sun, 01 Nov 2026 12:00:00 GMT'])->assertCreated();
        $this->assertSame('Mon, 09 Nov 2026 13:00:00 GMT', $past->headers->get('Expires'));

        $within = $this->register($path, 'ada', $this->adaToken, ['expires' => 'Wed, 11 Nov 2026 18:15:00 GMT'])->assertCreated();
        $this->assertSame('Wed, 11 Nov 2026 18:15:00 GMT', $within->headers->get('Expires'));

        $garbled = $this->register($path, 'ada', $this->adaToken, ['expires' => 'next tuesday'])->assertCreated();
        $this->assertSame('Thu, 12 Nov 2026 12:00:00 GMT', $garbled->headers->get('Expires'));

        CarbonImmutable::setTestNow();
    }

    public function test_an_unsupported_depth_falls_back_to_the_supported_one(): void
    {
        $calendar = $this->subscribe('/dav/calendars/ada/default/', 'ada', $this->adaToken, ['content' => 'infinity', 'property' => 'infinity']);
        $this->assertSame('1', $calendar->content_update_depth);
        $this->assertSame('0', $calendar->property_update_depth);

        // A property update on a home is not supported and is ignored.
        $home = $this->subscribe('/dav/calendars/ada/', 'ada', $this->adaToken, ['content' => '1', 'property' => '0']);
        $this->assertSame('1', $home->content_update_depth);
        $this->assertNull($home->property_update_depth);

        // A shallower depth than the supported one is kept.
        $shallow = $this->subscribe('/dav/addressbooks/ada/default/', 'ada', $this->adaToken, ['content' => '0']);
        $this->assertSame('0', $shallow->content_update_depth);
    }

    public function test_each_precondition_is_a_403_with_its_element(): void
    {
        $path = '/dav/calendars/ada/default/';
        [$publicKey] = $this->clientKeys();
        $offCurve = self::base64Url("\x04".str_repeat("\x01", 64));
        $invalid = [
            ['resource' => 'http://push.example.net/up/1'],
            ['resource' => 'https://user:secret@push.example.net/up/1'],
            ['resource' => 'not a url'],
            ['encoding' => 'aesgcm'],
            ['keyType' => 'p384dh'],
            ['publicKey' => self::base64Url(random_bytes(65))],
            ['publicKey' => $offCurve],
            ['publicKey' => substr($publicKey, 0, 40)],
            ['authSecret' => self::base64Url(random_bytes(12))],
            ['authSecret' => '!!!'],
        ];

        foreach ($invalid as $options) {
            $response = $this->register($path, 'ada', $this->adaToken, $options)->assertForbidden();
            $body = $this->body($response);
            $this->assertStringContainsString('invalid-subscription', $body, json_encode($options, JSON_THROW_ON_ERROR));
            $this->assertStringContainsString('xmlns:P="https://bitfire.at/webdav-push"', $body);
        }

        $noResource = str_replace('<P:push-resource>', '<P:other>', str_replace('</P:push-resource>', '</P:other>', $this->registerBody()));
        $response = $this->dav('POST', $path, 'ada', $this->adaToken, ['Content-Type' => 'application/xml'], $noResource)->assertForbidden();
        $this->assertStringContainsString('invalid-subscription', $this->body($response));

        $noTrigger = $this->register($path, 'ada', $this->adaToken, ['content' => null])->assertForbidden();
        $this->assertStringContainsString('no-trigger-supported', $this->body($noTrigger));
        $homeProperty = $this->register('/dav/calendars/ada/', 'ada', $this->adaToken, ['content' => null, 'property' => '0'])->assertForbidden();
        $this->assertStringContainsString('no-trigger-supported', $this->body($homeProperty));

        foreach (['/dav/principals/ada/', '/dav/calendars/', '/dav/'] as $notCapable) {
            $response = $this->register($notCapable, 'ada', $this->adaToken)->assertForbidden();
            $this->assertStringContainsString('push-not-available', $this->body($response), $notCapable);
        }

        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_a_plain_http_push_service_is_accepted_only_with_private_networks_allowed(): void
    {
        $this->register('/dav/calendars/ada/default/', 'ada', $this->adaToken, ['resource' => 'http://ntfy.lan/up/1'])->assertForbidden();

        config(['nexusdav.push.allow_private' => true]);
        $this->register('/dav/calendars/ada/default/', 'ada', $this->adaToken, ['resource' => 'http://ntfy.lan/up/1'])->assertCreated();
    }

    public function test_a_bad_body_and_another_users_collection_are_refused(): void
    {
        $this->dav('POST', '/dav/calendars/ada/default/', 'ada', $this->adaToken, ['Content-Type' => 'application/xml'], '<P:push-register xmlns:P="https://bitfire.at/webdav-push">')->assertStatus(400);
        $this->dav('POST', '/dav/calendars/ada/default/', 'ada', $this->adaToken, ['Content-Type' => 'application/xml'],
            '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "push-register">]><P:push-register xmlns:P="https://bitfire.at/webdav-push"/>')->assertStatus(400);

        $this->register('/dav/calendars/bob/default/', 'ada', $this->adaToken)->assertNotFound();
        $this->register('/dav/calendars/ada/missing/', 'ada', $this->adaToken)->assertNotFound();
        $this->register('/dav/calendars/ada/default/', 'ada', 'wrong')->assertUnauthorized();

        // A POST that is not a registration still reaches the other handlers.
        $this->dav('POST', '/dav/calendars/ada/default/', 'ada', $this->adaToken, ['Content-Type' => 'application/xml'], '<?xml version="1.0"?><x xmlns="urn:example"/>')->assertStatus(501);
        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_delete_on_the_registration_url_unregisters(): void
    {
        $response = $this->register('/dav/calendars/ada/default/', 'ada', $this->adaToken)->assertCreated();
        $path = (string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH);

        $this->dav('DELETE', $path, 'bob', $this->bobToken)->assertNotFound();
        $this->assertSame(1, PushSubscription::query()->count());

        $this->dav('GET', $path, 'ada', $this->adaToken)->assertStatus(405)->assertHeader('Allow', 'DELETE');
        $this->dav('PROPFIND', '/dav/push/', 'ada', $this->adaToken, ['Depth' => '1'])->assertStatus(405);

        $deleted = $this->dav('DELETE', $path, 'ada', $this->adaToken);
        $this->assertSame(204, $deleted->getStatusCode(), $this->body($deleted));
        $this->assertSame(0, PushSubscription::query()->count());

        $this->dav('DELETE', $path, 'ada', $this->adaToken)->assertNotFound();
        $this->dav('DELETE', '/dav/push/not-an-id', 'ada', $this->adaToken)->assertNotFound();
        $this->dav('DELETE', '/dav/push/', 'ada', $this->adaToken)->assertNotFound();
        $this->call('DELETE', $path)->assertUnauthorized();

        // An expired registration answers 404 as well.
        $expired = $this->subscribe('/dav/calendars/ada/default/', 'ada', $this->adaToken);
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->dav('DELETE', '/dav/push/'.$expired->registration, 'ada', $this->adaToken)->assertNotFound();
    }

    public function test_the_per_user_limit_refuses_new_registrations_only(): void
    {
        config(['nexusdav.push.max_subscriptions' => 2]);
        $resource = 'https://push.example.net/up/kept';

        $this->register('/dav/calendars/ada/default/', 'ada', $this->adaToken, ['resource' => $resource])->assertCreated();
        $this->register('/dav/addressbooks/ada/default/', 'ada', $this->adaToken)->assertCreated();

        $refused = $this->register('/dav/calendars/ada/', 'ada', $this->adaToken)->assertForbidden();
        $this->assertStringContainsString('push-not-available', $this->body($refused));
        $this->assertStringContainsString('Too many push subscriptions', $this->body($refused));

        // Renewing one that exists, and another user, are not limited.
        $this->register('/dav/calendars/ada/default/', 'ada', $this->adaToken, ['resource' => $resource])->assertNoContent();
        $this->register('/dav/calendars/bob/default/', 'bob', $this->bobToken)->assertCreated();

        // Expired rows do not count.
        PushSubscription::query()->where('user_id', $this->ada->getKey())->update(['expires_at' => now()->subMinute()]);
        $this->register('/dav/calendars/ada/', 'ada', $this->adaToken)->assertCreated();
        $this->assertSame(1, PushSubscription::query()->where('user_id', $this->ada->getKey())->count());
    }

    public function test_the_client_keys_are_stored_encrypted(): void
    {
        [$publicKey, $authSecret] = $this->clientKeys();
        $row = $this->subscribe('/dav/calendars/ada/default/', 'ada', $this->adaToken, ['publicKey' => $publicKey, 'authSecret' => $authSecret]);

        $raw = DB::table('push_subscriptions')->where('id', $row->id)->first(['public_key', 'auth_secret']);
        $this->assertNotNull($raw);
        $this->assertNotSame($publicKey, $raw->public_key);
        $this->assertStringNotContainsString($publicKey, (string) $raw->public_key);
        $this->assertStringNotContainsString($authSecret, (string) $raw->auth_secret);
        $this->assertSame($publicKey, Crypt::decryptString((string) $raw->public_key));
        $this->assertSame($authSecret, Crypt::decryptString((string) $raw->auth_secret));
        $this->assertSame($publicKey, $row->fresh()?->public_key);
        $this->assertArrayNotHasKey('public_key', $row->toArray());
    }

    public function test_revoking_the_app_password_or_disabling_the_user_removes_subscriptions(): void
    {
        $second = $this->token($this->ada);
        $first = $this->subscribe('/dav/calendars/ada/default/', 'ada', $this->adaToken);
        $other = $this->subscribe('/dav/addressbooks/ada/default/', 'ada', $second);

        $password = AppPassword::query()->findOrFail($first->app_password_id);
        app(AppPasswordService::class)->revoke($password);
        $this->assertNull($first->fresh());
        $this->assertNotNull($other->fresh());

        // Deleting the app password row goes the same way.
        $bobRow = $this->subscribe('/dav/calendars/bob/default/', 'bob', $this->bobToken);
        AppPassword::query()->whereKey($bobRow->app_password_id)->delete();
        $this->assertNull($bobRow->fresh());

        app(UserService::class)->update($this->ada, ['is_active' => false]);
        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_deleting_a_user_removes_their_subscriptions(): void
    {
        $this->subscribe('/dav/calendars/ada/default/', 'ada', $this->adaToken);
        $bob = $this->subscribe('/dav/calendars/bob/default/', 'bob', $this->bobToken);

        app(UserService::class)->delete($this->ada);

        $this->assertSame([$bob->id], PushSubscription::query()->pluck('id')->all());
    }

    public function test_deleting_a_collection_or_removing_a_share_removes_its_subscriptions(): void
    {
        $calendar = CalendarInstance::query()->where('principaluri', 'principals/ada')->where('uri', 'default')->firstOrFail();
        $share = app(SharingService::class)->share($calendar, $this->bob, SharingService::READ);
        $owner = $this->subscribe('/dav/calendars/ada/default/', 'ada', $this->adaToken);
        $sharee = $this->subscribe('/dav/calendars/bob/'.$share->uri.'/', 'bob', $this->bobToken);
        $home = $this->subscribe('/dav/calendars/bob/', 'bob', $this->bobToken);

        app(SharingService::class)->unshare($calendar, $this->bob);
        $this->assertNull($sharee->fresh());
        $this->assertNotNull($owner->fresh());
        $this->assertNotNull($home->fresh());

        // The owner's delete: with the sync queue its last message is sent at once.
        $this->dav('DELETE', '/dav/calendars/ada/default/', 'ada', $this->adaToken)->assertNoContent();
        $this->assertNull($owner->fresh());

        $book = $this->subscribe('/dav/addressbooks/bob/default/', 'bob', $this->bobToken);
        config(['nexusdav.features.push' => false]);
        $this->dav('DELETE', '/dav/addressbooks/bob/default/', 'bob', $this->bobToken)->assertNoContent();
        $this->assertNull($book->fresh());
        $this->assertNotNull($home->fresh());
    }

    public function test_a_sharee_leaving_an_address_book_removes_only_their_subscription(): void
    {
        $book = AddressBook::query()->where('principaluri', 'principals/ada')->where('uri', 'default')->firstOrFail();
        $share = app(SharingService::class)->share($book, $this->bob, SharingService::READ_WRITE);
        $owner = $this->subscribe('/dav/addressbooks/ada/default/', 'ada', $this->adaToken);
        $sharee = $this->subscribe('/dav/addressbooks/bob/'.$share->uri.'/', 'bob', $this->bobToken);

        $this->dav('DELETE', '/dav/addressbooks/bob/'.$share->uri.'/', 'bob', $this->bobToken)->assertNoContent();

        $this->assertNull($sharee->fresh());
        $this->assertNotNull($owner->fresh());
    }

    public function test_file_folders_offer_push_only_with_include_files_on(): void
    {
        $base = sys_get_temp_dir().'/nexusdav-push-files-'.bin2hex(random_bytes(6));
        config(['nexusdav.files.path' => $base, 'nexusdav.features.files' => true]);

        try {
            $this->dav('MKCOL', '/dav/files/ada/docs/', 'ada', $this->adaToken)->assertCreated();
            $refused = $this->register('/dav/files/ada/docs/', 'ada', $this->adaToken)->assertForbidden();
            $this->assertStringContainsString('push-not-available', $this->body($refused));
            $props = $this->body($this->dav('PROPFIND', '/dav/files/ada/', 'ada', $this->adaToken, ['Depth' => '0'], $this->pushProps()));
            $this->assertStringNotContainsString('<P:topic>', $props);

            config(['nexusdav.push.files' => true]);

            $home = $this->body($this->dav('PROPFIND', '/dav/files/ada/', 'ada', $this->adaToken, ['Depth' => '0'], $this->pushProps())->assertStatus(207));
            $this->assertStringContainsString('<P:content-update><d:depth>infinity</d:depth></P:content-update>', $home);
            $folder = $this->body($this->dav('PROPFIND', '/dav/files/ada/docs/', 'ada', $this->adaToken, ['Depth' => '0'], $this->pushProps())->assertStatus(207));
            $this->assertStringContainsString('<P:content-update><d:depth>1</d:depth></P:content-update>', $folder);
            $this->assertStringNotContainsString('property-update', $folder);

            $homeRow = $this->subscribe('/dav/files/ada/', 'ada', $this->adaToken, ['content' => 'infinity']);
            $this->assertSame('infinity', $homeRow->content_update_depth);
            $this->assertSame('files/ada', $homeRow->path);
            $this->assertSame('1', $this->subscribe('/dav/files/ada/docs/', 'ada', $this->adaToken, ['content' => 'infinity'])->content_update_depth);

            // A file is not a collection and has no topic.
            $this->dav('PUT', '/dav/files/ada/docs/a.txt', 'ada', $this->adaToken, [], 'hello')->assertCreated();
            $this->register('/dav/files/ada/docs/a.txt', 'ada', $this->adaToken)->assertForbidden();
        } finally {
            (new FileStorage)->deleteTree($base);
        }
    }

    public function test_pruning_removes_expired_and_long_retired_rows(): void
    {
        $expired = $this->subscribe('/dav/calendars/ada/default/', 'ada', $this->adaToken);
        $retired = $this->subscribe('/dav/addressbooks/ada/default/', 'ada', $this->adaToken);
        $fresh = $this->subscribe('/dav/calendars/ada/', 'ada', $this->adaToken);
        $recent = $this->subscribe('/dav/addressbooks/ada/', 'ada', $this->adaToken);

        $expired->forceFill(['expires_at' => now()->subSecond()])->save();
        $retired->forceFill(['removed_at' => now()->subMinutes(PushSubscriptions::RETIRED_MINUTES + 1)])->save();
        $recent->forceFill(['removed_at' => now()->subMinute()])->save();

        app(PushSubscriptions::class)->prune();

        $this->assertEqualsCanonicalizing([$fresh->id, $recent->id], PushSubscription::query()->pluck('id')->all());
    }
}
