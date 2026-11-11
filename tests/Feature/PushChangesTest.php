<?php

namespace Tests\Feature;

use App\Jobs\DeliverPushMessage;
use App\Jobs\SendPushMessages;
use App\Models\AddressBook;
use App\Models\CalendarInstance;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Dav\FileStorage;
use App\Services\Dav\SharingService;
use App\Services\Holidays\HolidayCalendarService;
use App\Services\Push\PushChanges;
use App\Services\Push\PushResult;
use App\Services\Push\PushSender;
use App\Services\Push\PushSubscriptions;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PushHelpers;
use Tests\TestCase;

/**
 * PR 36: change events, the merge window and Push-Dont-Notify
 * (docs/design/webdav-push.md, "Sending").
 */
class PushChangesTest extends TestCase
{
    use PushHelpers, RefreshDatabase;

    private const CALENDAR = '/dav/calendars/ada/default/';

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

    public function test_an_object_write_queues_one_job_per_topic_and_later_writes_merge_into_it(): void
    {
        $subscription = $this->subscribe(self::CALENDAR, 'ada', $this->adaToken);
        Queue::fake();

        $this->putEvent('a', 'ada', $this->adaToken);
        $this->putEvent('b', 'ada', $this->adaToken);
        $this->dav('DELETE', self::CALENDAR.'a.ics', 'ada', $this->adaToken)->assertNoContent();

        Queue::assertPushed(SendPushMessages::class, 1);
        Queue::assertPushed(SendPushMessages::class, fn (SendPushMessages $job): bool => $job->topic === $subscription->topic
            && $job->delay !== null);
        $row = $this->outbox($subscription->topic);
        $this->assertSame(1, (int) $row->content_depth);
        $this->assertFalse((bool) $row->property_update);
        $this->assertSame('[]', $row->skip);
        $this->assertSame(1, DB::table('push_outbox')->count());

        // The job closes the window; the next change opens a new one.
        $this->runSend($subscription->topic);
        $this->assertSame(0, DB::table('push_outbox')->count());
        $this->putEvent('c', 'ada', $this->adaToken);
        Queue::assertPushed(SendPushMessages::class, 2);
    }

    public function test_a_change_without_subscribers_or_with_push_off_queues_nothing(): void
    {
        Queue::fake();
        $this->putEvent('a', 'ada', $this->adaToken);
        $this->assertSame(0, DB::table('push_outbox')->count());

        $this->subscribe(self::CALENDAR, 'ada', $this->adaToken);
        config(['nexusdav.features.push' => false]);
        $this->putEvent('b', 'ada', $this->adaToken);

        $this->assertSame(0, DB::table('push_outbox')->count());
        Queue::assertNothingPushed();
    }

    public function test_the_message_carries_the_latest_sync_token(): void
    {
        $subscription = $this->subscribe(self::CALENDAR, 'ada', $this->adaToken);
        Queue::fake();
        $this->putEvent('a', 'ada', $this->adaToken);
        $this->putEvent('b', 'ada', $this->adaToken);

        $messages = $this->runSend($subscription->topic);

        $props = $this->body($this->dav('PROPFIND', self::CALENDAR, 'ada', $this->adaToken, ['Depth' => '0'], '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:sync-token/></d:prop></d:propfind>'));
        $this->assertSame(1, preg_match('#<d:sync-token>([^<]+)</d:sync-token>#', $props, $token));
        $this->assertSame([$subscription->id => '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<P:push-message xmlns:D="DAV:" xmlns:P="https://bitfire.at/webdav-push">'
            .'<P:topic>'.$subscription->topic.'</P:topic>'
            .'<P:content-update><D:sync-token>'.$token[1].'</D:sync-token></P:content-update>'
            .'</P:push-message>'."\n"], $messages);
    }

    public function test_a_property_change_is_a_property_update_and_a_content_update_of_the_home(): void
    {
        $content = $this->subscribe(self::CALENDAR, 'ada', $this->adaToken, ['resource' => 'https://push.example.net/content']);
        $property = $this->subscribe(self::CALENDAR, 'ada', $this->adaToken, ['content' => null, 'property' => '0']);
        $home = $this->subscribe('/dav/calendars/ada/', 'ada', $this->adaToken);
        Queue::fake();

        $this->dav('PROPPATCH', self::CALENDAR, 'ada', $this->adaToken, ['Content-Type' => 'application/xml'],
            '<?xml version="1.0"?><d:propertyupdate xmlns:d="DAV:"><d:set><d:prop><d:displayname>Work</d:displayname></d:prop></d:set></d:propertyupdate>')->assertStatus(207);

        $row = $this->outbox($property->topic);
        $this->assertNull($row->content_depth);
        $this->assertTrue((bool) $row->property_update);
        $this->assertSame(1, (int) $this->outbox($home->topic)->content_depth);

        // Only the property subscriber hears about it.
        $messages = $this->runSend($property->topic);
        $this->assertSame([$property->id], array_keys($messages));
        $this->assertStringContainsString('<P:property-update/>', $messages[$property->id]);
        $this->assertStringNotContainsString('content-update', $messages[$property->id]);
        $this->assertArrayNotHasKey($content->id, $messages);

        // Merged with a content change, each subscriber gets its own part.
        $this->putEvent('a', 'ada', $this->adaToken);
        $this->dav('PROPPATCH', self::CALENDAR, 'ada', $this->adaToken, ['Content-Type' => 'application/xml'],
            '<?xml version="1.0"?><d:propertyupdate xmlns:d="DAV:"><d:set><d:prop><d:displayname>Home</d:displayname></d:prop></d:set></d:propertyupdate>')->assertStatus(207);
        $merged = $this->runSend($content->topic);
        $this->assertStringContainsString('<P:content-update>', $merged[$content->id]);
        $this->assertStringNotContainsString('property-update', $merged[$content->id]);
        $this->assertStringContainsString('<P:property-update/>', $merged[$property->id]);
        $this->assertStringNotContainsString('content-update', $merged[$property->id]);
    }

    public function test_cards_and_address_book_homes_queue_their_topics(): void
    {
        $book = $this->subscribe('/dav/addressbooks/ada/default/', 'ada', $this->adaToken);
        $home = $this->subscribe('/dav/addressbooks/ada/', 'ada', $this->adaToken);
        $calendarHome = $this->subscribe('/dav/calendars/ada/', 'ada', $this->adaToken);
        Queue::fake();

        $this->dav('PUT', '/dav/addressbooks/ada/default/c.vcf', 'ada', $this->adaToken, ['Content-Type' => 'text/vcard'], $this->card('c'))->assertCreated();
        $this->assertSame(1, (int) $this->outbox($book->topic)->content_depth);
        $this->assertNull(DB::table('push_outbox')->where('topic', $home->topic)->first());

        $this->dav('MKCOL', '/dav/addressbooks/ada/work/', 'ada', $this->adaToken, ['Content-Type' => 'application/xml'],
            '<?xml version="1.0"?><d:mkcol xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:carddav"><d:set><d:prop><d:resourcetype><d:collection/><c:addressbook/></d:resourcetype></d:prop></d:set></d:mkcol>')->assertCreated();
        $this->assertSame(1, (int) $this->outbox($home->topic)->content_depth);

        $this->dav('MKCALENDAR', '/dav/calendars/ada/work/', 'ada', $this->adaToken)->assertCreated();
        $this->assertSame(1, (int) $this->outbox($calendarHome->topic)->content_depth);
    }

    public function test_a_change_through_a_share_reaches_every_subscriber_through_one_topic(): void
    {
        $calendar = CalendarInstance::query()->where('principaluri', 'principals/ada')->where('uri', 'default')->firstOrFail();
        $share = app(SharingService::class)->share($calendar, $this->bob, SharingService::READ_WRITE);
        $owner = $this->subscribe(self::CALENDAR, 'ada', $this->adaToken);
        $sharee = $this->subscribe('/dav/calendars/bob/'.$share->uri.'/', 'bob', $this->bobToken);
        $this->assertSame($owner->topic, $sharee->topic);
        Queue::fake();

        $this->dav('PUT', '/dav/calendars/bob/'.$share->uri.'/s.ics', 'bob', $this->bobToken, ['Content-Type' => 'text/calendar'], $this->event('s'))->assertCreated();
        $this->putEvent('o', 'ada', $this->adaToken);

        Queue::assertPushed(SendPushMessages::class, 1);
        $this->assertEqualsCanonicalizing([$owner->id, $sharee->id], array_keys($this->runSend($owner->topic)));
    }

    public function test_sharing_and_unsharing_change_the_sharees_home(): void
    {
        $home = $this->subscribe('/dav/calendars/bob/', 'bob', $this->bobToken);
        $bookHome = $this->subscribe('/dav/addressbooks/bob/', 'bob', $this->bobToken);
        Queue::fake();
        $calendar = CalendarInstance::query()->where('principaluri', 'principals/ada')->where('uri', 'default')->firstOrFail();
        $book = AddressBook::query()->where('principaluri', 'principals/ada')->where('uri', 'default')->firstOrFail();

        app(SharingService::class)->share($calendar, $this->bob, SharingService::READ);
        app(SharingService::class)->share($book, $this->bob, SharingService::READ);
        $this->assertSame(1, (int) $this->outbox($home->topic)->content_depth);
        $this->assertSame(1, (int) $this->outbox($bookHome->topic)->content_depth);

        $this->runSend($home->topic);
        app(SharingService::class)->unshare($calendar, $this->bob);
        $this->assertSame(1, (int) $this->outbox($home->topic)->content_depth);
    }

    public function test_push_dont_notify_skips_the_callers_own_registrations_only(): void
    {
        $calendar = CalendarInstance::query()->where('principaluri', 'principals/ada')->where('uri', 'default')->firstOrFail();
        $share = app(SharingService::class)->share($calendar, $this->bob, SharingService::READ);
        $phone = $this->subscribe(self::CALENDAR, 'ada', $this->adaToken);
        $laptop = $this->subscribe(self::CALENDAR, 'ada', $this->adaToken);
        $bob = $this->subscribe('/dav/calendars/bob/'.$share->uri.'/', 'bob', $this->bobToken);
        $subscriptions = app(PushSubscriptions::class);
        Queue::fake();

        // Ada's phone names itself and Bob's registration: Bob's is ignored.
        $this->putEvent('a', 'ada', $this->adaToken, [
            'Push-Dont-Notify' => '"'.$subscriptions->registrationUrl($phone->registration).'", "'.$subscriptions->registrationUrl($bob->registration).'", "https://elsewhere.example/x"',
        ]);
        $this->assertSame([$phone->registration], json_decode((string) $this->outbox($phone->topic)->skip, true));
        $this->assertEqualsCanonicalizing([$laptop->id, $bob->id], array_keys($this->runSend($phone->topic)));

        // A second change in the window without the header: nobody is skipped.
        $this->putEvent('b', 'ada', $this->adaToken, ['Push-Dont-Notify' => '"'.$subscriptions->registrationUrl($phone->registration).'"']);
        $this->putEvent('c', 'ada', $this->adaToken);
        $this->assertSame([], json_decode((string) $this->outbox($phone->topic)->skip, true));
        $this->assertEqualsCanonicalizing([$phone->id, $laptop->id, $bob->id], array_keys($this->runSend($phone->topic)));

        // `*` sends nothing for that change.
        $this->putEvent('d', 'ada', $this->adaToken, ['Push-Dont-Notify' => '*']);
        $this->assertSame(0, DB::table('push_outbox')->count());

        // The header does not outlive its request.
        app(SharingService::class)->unshare($calendar, $this->bob);
        $this->putEvent('e', 'ada', $this->adaToken);
        $this->assertNotNull(DB::table('push_outbox')->where('topic', $phone->topic)->first());
    }

    public function test_deleting_a_collection_sends_its_subscribers_one_last_update(): void
    {
        $calendar = CalendarInstance::query()->where('principaluri', 'principals/ada')->where('uri', 'default')->firstOrFail();
        $share = app(SharingService::class)->share($calendar, $this->bob, SharingService::READ);
        $owner = $this->subscribe(self::CALENDAR, 'ada', $this->adaToken);
        $sharee = $this->subscribe('/dav/calendars/bob/'.$share->uri.'/', 'bob', $this->bobToken);
        $properties = $this->subscribe(self::CALENDAR, 'ada', $this->adaToken, ['content' => null, 'property' => '0']);
        $bobHome = $this->subscribe('/dav/calendars/bob/', 'bob', $this->bobToken);
        Queue::fake();

        $this->dav('DELETE', self::CALENDAR, 'ada', $this->adaToken)->assertNoContent();

        $this->assertNotNull($owner->fresh()?->removed_at);
        $this->assertNotNull($sharee->fresh()?->removed_at);
        $this->assertSame(0, (int) $this->outbox($owner->topic)->content_depth);
        $this->assertSame(1, (int) $this->outbox($bobHome->topic)->content_depth);
        $this->assertSame(0, app(PushSubscriptions::class)->activeCount((int) $this->ada->getKey()));

        $messages = $this->runSend($owner->topic);
        $this->assertEqualsCanonicalizing([$owner->id, $sharee->id], array_keys($messages));
        $this->assertStringContainsString('<P:content-update/>', $messages[$owner->id]);
        // A property-only subscriber is not reached by a deletion and goes now.
        $this->assertNull($properties->fresh());

        // Each retired row goes once its message is sent.
        $sent = $this->fakeSender();
        $this->deliver($owner->id, $owner->topic, $messages[$owner->id]);
        $this->deliver($sharee->id, $sharee->topic, $messages[$sharee->id]);
        $this->assertCount(2, $sent->messages);
        $this->assertNull($owner->fresh());
        $this->assertNull($sharee->fresh());
        $this->assertNotNull($bobHome->fresh());
    }

    public function test_deleting_the_owner_sends_the_sharees_a_last_update(): void
    {
        $book = AddressBook::query()->where('principaluri', 'principals/ada')->where('uri', 'default')->firstOrFail();
        $share = app(SharingService::class)->share($book, $this->bob, SharingService::READ);
        $own = $this->subscribe('/dav/addressbooks/ada/default/', 'ada', $this->adaToken);
        $sharee = $this->subscribe('/dav/addressbooks/bob/'.$share->uri.'/', 'bob', $this->bobToken);
        $bobHome = $this->subscribe('/dav/addressbooks/bob/', 'bob', $this->bobToken);
        Queue::fake();

        app(UserService::class)->delete($this->ada);

        $this->assertNull($own->fresh());
        $this->assertNotNull($sharee->fresh()?->removed_at);
        $this->assertSame(0, (int) $this->outbox($sharee->topic)->content_depth);
        $this->assertSame(1, (int) $this->outbox($bobHome->topic)->content_depth);
        $this->assertSame([$sharee->id], array_keys($this->runSend($sharee->topic)));
    }

    public function test_file_changes_reach_the_folder_and_the_file_home(): void
    {
        $base = sys_get_temp_dir().'/nexusdav-push-changes-'.bin2hex(random_bytes(6));
        config(['nexusdav.files.path' => $base, 'nexusdav.features.files' => true, 'nexusdav.push.files' => true]);

        try {
            $this->dav('MKCOL', '/dav/files/ada/docs/', 'ada', $this->adaToken)->assertCreated();
            $this->dav('MKCOL', '/dav/files/ada/docs/old/', 'ada', $this->adaToken)->assertCreated();
            $home = $this->subscribe('/dav/files/ada/', 'ada', $this->adaToken, ['content' => 'infinity']);
            $shallowHome = $this->subscribe('/dav/files/ada/', 'ada', $this->adaToken, ['content' => '1']);
            $docs = $this->subscribe('/dav/files/ada/docs/', 'ada', $this->adaToken);
            $old = $this->subscribe('/dav/files/ada/docs/old/', 'ada', $this->adaToken);
            Queue::fake();

            $this->dav('PUT', '/dav/files/ada/docs/a.txt', 'ada', $this->adaToken, [], 'one')->assertCreated();
            $this->dav('PUT', '/dav/files/ada/docs/a.txt', 'ada', $this->adaToken, [], 'two')->assertNoContent();
            $this->assertSame(1, (int) $this->outbox($docs->topic)->content_depth);
            $this->assertSame(2, (int) $this->outbox($home->topic)->content_depth);
            $this->assertNull(DB::table('push_outbox')->where('topic', $old->topic)->first());

            // A deep change reaches only the depth-infinity home subscriber.
            $this->assertSame([$home->id], array_keys($this->runSend($home->topic)));
            $this->assertArrayHasKey($docs->id, $this->runSend($docs->topic));

            // Deleting a folder is the last update for it and the folders inside.
            $this->dav('DELETE', '/dav/files/ada/docs/', 'ada', $this->adaToken)->assertNoContent();
            $this->assertNotNull($docs->fresh()?->removed_at);
            $this->assertNotNull($old->fresh()?->removed_at);
            $this->assertSame(0, (int) $this->outbox($old->topic)->content_depth);
            $this->assertSame(0, (int) $this->outbox($docs->topic)->content_depth);
            $this->assertEqualsCanonicalizing([$home->id, $shallowHome->id], array_keys($this->runSend($home->topic)));

            // Without Include Files in push, a delete just removes the rows.
            $this->dav('MKCOL', '/dav/files/ada/tmp/', 'ada', $this->adaToken)->assertCreated();
            $tmp = $this->subscribe('/dav/files/ada/tmp/', 'ada', $this->adaToken);
            config(['nexusdav.push.files' => false]);
            DB::table('push_outbox')->delete();
            $this->dav('DELETE', '/dav/files/ada/tmp/', 'ada', $this->adaToken)->assertNoContent();
            $this->assertNull($tmp->fresh());
            $this->assertSame(0, DB::table('push_outbox')->count());
        } finally {
            (new FileStorage)->deleteTree($base);
        }
    }

    public function test_a_rolled_back_change_queues_nothing(): void
    {
        $subscription = $this->subscribe(self::CALENDAR, 'ada', $this->adaToken);
        Queue::fake();
        $calendarId = (int) CalendarInstance::query()->where('principaluri', 'principals/ada')->value('calendarid');

        DB::beginTransaction();
        app(PushChanges::class)->calendarChanged($calendarId, 'x.ics');
        DB::rollBack();
        $this->assertSame(0, DB::table('push_outbox')->count());

        DB::transaction(fn () => app(PushChanges::class)->calendarChanged($calendarId, 'x.ics'));
        $this->assertNotNull(DB::table('push_outbox')->where('topic', $subscription->topic)->first());
    }

    public function test_a_failing_queue_never_fails_the_write(): void
    {
        $subscription = $this->subscribe(self::CALENDAR, 'ada', $this->adaToken);
        Queue::shouldReceive('connection')->andThrow(new \RuntimeException('The queue is down.'));

        $this->putEvent('a', 'ada', $this->adaToken);

        // The outbox row went back with its job, so the next change retries.
        $this->assertSame(0, DB::table('push_outbox')->where('topic', $subscription->topic)->count());
        $this->assertSame(1, DB::table('calendarobjects')->where('uri', 'a.ics')->count());
    }

    public function test_delivery_skips_removed_and_expired_subscriptions(): void
    {
        $subscription = $this->subscribe(self::CALENDAR, 'ada', $this->adaToken);
        $sent = $this->fakeSender();

        $this->deliver($subscription->id, $subscription->topic, '<m/>');
        $this->assertSame([[$subscription->id, $subscription->topic, '<m/>']], $sent->messages);

        $subscription->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->deliver($subscription->id, $subscription->topic, '<m/>');
        $subscription->delete();
        $this->deliver($subscription->id, $subscription->topic, '<m/>');
        $this->assertCount(1, $sent->messages);
    }

    public function test_a_queued_window_is_dropped_when_push_is_switched_off(): void
    {
        $subscription = $this->subscribe(self::CALENDAR, 'ada', $this->adaToken);
        Queue::fake();
        $this->putEvent('a', 'ada', $this->adaToken);

        config(['nexusdav.features.push' => false]);
        $this->assertSame([], $this->runSend($subscription->topic));
        $this->assertSame(0, DB::table('push_outbox')->count());
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function test_a_holiday_calendar_change_reaches_its_subscribers_once(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 10));
        $holiday = app(HolidayCalendarService::class)->create($this->ada, 'holidays', 'Holidays', '#3366CC', 'CA-ON');
        $subscription = $this->subscribe('/dav/calendars/ada/holidays/', 'ada', $this->adaToken);
        Queue::fake();

        app(HolidayCalendarService::class)->refresh($holiday);
        $this->assertSame(0, DB::table('push_outbox')->count());
        Queue::assertNothingPushed();

        app(HolidayCalendarService::class)->update($holiday, 'CA-QC', 'en_CA', ['official']);

        Queue::assertPushed(SendPushMessages::class, 1);
        $this->assertSame(1, (int) $this->outbox($subscription->topic)->content_depth);
    }

    private function putEvent(string $uid, string $username, string $password, array $headers = []): void
    {
        $this->dav('PUT', '/dav/calendars/'.$username.'/default/'.$uid.'.ics', $username, $password, ['Content-Type' => 'text/calendar'] + $headers, $this->event($uid))->assertCreated();
    }

    private function outbox(string $topic): object
    {
        $row = DB::table('push_outbox')->where('topic', $topic)->first();
        $this->assertNotNull($row, 'No push_outbox row for '.$topic);

        return $row;
    }

    /**
     * Runs the topic's job and returns the queued messages by subscription.
     * Queue::fake() must be on.
     *
     * @return array<int, string>
     */
    private function runSend(string $topic): array
    {
        $before = Queue::pushed(DeliverPushMessage::class)->count();
        (new SendPushMessages($topic))->handle(app(PushSubscriptions::class));

        $messages = [];

        foreach (Queue::pushed(DeliverPushMessage::class)->slice($before) as $job) {
            $this->assertSame($topic, $job->topic);
            $messages[$job->subscriptionId] = $job->message;
        }

        return $messages;
    }

    private function deliver(int $subscriptionId, string $topic, string $message): void
    {
        (new DeliverPushMessage($subscriptionId, $topic, $message))->handle(app(PushSender::class));
    }

    private function fakeSender(): object
    {
        $sender = new class implements PushSender
        {
            /**
             * @var list<array{0: int, 1: string, 2: string}>
             */
            public array $messages = [];

            public function send(PushSubscription $subscription, string $topic, string $message): PushResult
            {
                $this->messages[] = [$subscription->id, $topic, $message];

                return PushResult::fromStatus(201);
            }
        };
        $this->app->instance(PushSender::class, $sender);

        return $sender;
    }
}
