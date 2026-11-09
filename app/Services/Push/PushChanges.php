<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\DB;

/**
 * Where a change becomes push work (PR 36 of the Version 1.x plan).
 *
 * The backends, the sharing service, user deletion and the file events call
 * this when a write moves a collection's sync token, adds or removes a
 * collection in a home, or changes a file folder. A change is queued once
 * its transaction commits (PushQueue), so a rolled-back write sends
 * nothing, and only for topics that have subscriptions.
 *
 * Content depths: 0 the subscribed collection itself (it was deleted),
 * 1 a member, 2 deeper (the file home, which supports depth infinity).
 */
final class PushChanges
{
    public const SELF = 0;

    public const MEMBER = 1;

    public const DEEPER = 2;

    private bool $quiet = false;

    /**
     * @var list<string>
     */
    private array $skip = [];

    public function __construct(
        private PushTopics $topics,
        private PushSubscriptions $subscriptions,
        private PushQueue $queue,
    ) {}

    /**
     * Push-Dont-Notify of the current DAV request: `*` (all) sends nothing
     * for its changes; otherwise the caller's own registrations are skipped.
     *
     * @param  list<string>  $registrations
     */
    public function dontNotify(bool $all, array $registrations): void
    {
        $this->quiet = $all;
        $this->skip = $all ? [] : array_values(array_unique($registrations));
    }

    public function reset(): void
    {
        $this->dontNotify(false, []);
    }

    /**
     * sabre's addChange: an object (content) or the calendar's own
     * properties (an empty object URI). A rename also changes the owner's
     * calendar home, which lists the calendar's name.
     */
    public function calendarChanged(int $calendarId, string $objectUri): void
    {
        $this->collectionChanged(true, $calendarId, $objectUri);
    }

    public function addressBookChanged(int $addressBookId, string $objectUri): void
    {
        $this->collectionChanged(false, $addressBookId, $objectUri);
    }

    /**
     * A collection was added to or removed from the principal's home.
     */
    public function homeChanged(bool $calendar, string $principalUri): void
    {
        if ($this->recording()) {
            $this->record($this->topics->home($calendar, $principalUri), self::MEMBER, false, null);
        }
    }

    /**
     * The owner deleted the collection. Its subscribers get one last content
     * update, then their rows go; each listed home gets a content update.
     *
     * @param  list<string>  $principals
     */
    public function collectionDeleted(bool $calendar, int $id, array $principals): void
    {
        if (! PushSubscription::query()->exists()) {
            return;
        }

        $topic = $calendar ? $this->topics->calendar($id) : $this->topics->addressBook($id);

        if (! $this->recording()) {
            $this->subscriptions->forgetTopic($topic);

            return;
        }

        $this->subscriptions->retireTopic($topic);
        $this->record($topic, self::SELF, false, null);

        foreach (array_unique($principals) as $principal) {
            $this->homeChanged($calendar, $principal);
        }
    }

    /**
     * A file or folder was created, written, deleted, or moved in or out at
     * this DAV path. Its folder gets a depth 1 update, the file home a depth
     * 1 or depth infinity one. A removed folder's subscribers, and those of
     * the folders inside it, get one last update; without push on files
     * their rows just go.
     */
    public function fileChanged(string $path, bool $removed): void
    {
        $path = PushTopics::filesPath($path);
        $segments = explode('/', $path);

        if (count($segments) < 3 || $segments[0] !== 'files') {
            return;
        }

        if (! PushConfig::files() || $this->quiet) {
            if ($removed) {
                $this->subscriptions->forgetPath($path);
            }

            return;
        }

        $home = 'files/'.$segments[1];
        $parent = implode('/', array_slice($segments, 0, -1));
        $this->record($this->topics->files($parent), self::MEMBER, false, null);

        if ($parent !== $home) {
            $this->record($this->topics->files($home), self::DEEPER, false, null);
        }

        if ($removed) {
            foreach ($this->subscriptions->retirePath($path) as $topic) {
                $this->record($topic, self::SELF, false, null);
            }
        }
    }

    private function collectionChanged(bool $calendar, int $id, string $objectUri): void
    {
        if (! $this->recording()) {
            return;
        }

        $topic = $calendar ? $this->topics->calendar($id) : $this->topics->addressBook($id);
        $source = ($calendar ? 'calendar:' : 'addressbook:').$id;

        if ($objectUri !== '') {
            $this->record($topic, self::MEMBER, false, $source);

            return;
        }

        $this->record($topic, null, true, $source);
        $owner = $calendar
            ? DB::table('calendarinstances')->where('calendarid', $id)->where('access', 1)->value('principaluri')
            : DB::table('addressbooks')->where('id', $id)->value('principaluri');

        if (is_string($owner) && $owner !== '') {
            $this->homeChanged($calendar, $owner);
        }
    }

    private function recording(): bool
    {
        return PushConfig::enabled() && ! $this->quiet;
    }

    /**
     * Queued after the surrounding transaction commits, at once without one.
     */
    private function record(string $topic, ?int $contentDepth, bool $property, ?string $source): void
    {
        if (! PushSubscription::query()->where('topic', $topic)->exists()) {
            return;
        }

        $skip = $this->skip;

        DB::afterCommit(function () use ($topic, $contentDepth, $property, $source, $skip): void {
            $this->queue->add($topic, $contentDepth, $property, $source, $skip);
        });
    }
}
