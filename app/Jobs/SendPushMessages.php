<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Services\Push\PushConfig;
use App\Services\Push\PushMessage;
use App\Services\Push\PushSubscriptions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Closes a topic's merge window: takes its push_outbox row and queues one
 * message for each subscription that wants it. Each subscription gets its
 * own delivery job, so one slow push service never holds up the others.
 */
class SendPushMessages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public string $topic) {}

    public function handle(PushSubscriptions $subscriptions): void
    {
        $row = DB::transaction(function (): ?object {
            $row = DB::table('push_outbox')->where('topic', $this->topic)->lockForUpdate()->first();

            if ($row !== null) {
                DB::table('push_outbox')->where('id', $row->id)->delete();
            }

            return $row;
        });

        // Push switched off since the change: its messages are dropped.
        if ($row === null || ! PushConfig::enabled()) {
            return;
        }

        $subscriptions->prune();

        $contentDepth = $row->content_depth === null ? null : (int) $row->content_depth;
        $property = (bool) $row->property_update;
        $stored = json_decode((string) $row->skip, true);
        $skip = is_array($stored) ? $stored : [];
        $syncToken = $contentDepth === null ? null : PushMessage::syncToken($row->source === null ? null : (string) $row->source);
        $queued = 0;

        $matches = PushSubscription::query()
            ->where('topic', $this->topic)
            ->where('expires_at', '>', now())
            ->orderBy('id')
            ->get();

        foreach ($matches as $subscription) {
            $parts = PushMessage::parts($subscription, $contentDepth, $property);

            if (in_array($subscription->registration, $skip, true) || (! $parts['content'] && ! $parts['property'])) {
                // A retired row that this last change does not reach has
                // nothing left to wait for.
                if ($subscription->removed_at !== null) {
                    $subscription->delete();
                }

                continue;
            }

            DeliverPushMessage::dispatch(
                $subscription->id,
                $this->topic,
                PushMessage::xml($this->topic, $parts['content'], $parts['content'] ? $syncToken : null, $parts['property']),
            );
            $queued++;
        }

        PushConfig::log('debug', 'Push messages queued', ['topic' => $this->topic, 'subscriptions' => $queued]);
    }
}
