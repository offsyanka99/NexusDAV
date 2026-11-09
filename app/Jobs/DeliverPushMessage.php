<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Services\Push\PushConfig;
use App\Services\Push\PushResult;
use App\Services\Push\PushSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;

/**
 * Sends one push message to one subscription and acts on the answer
 * (docs/design/webdav-push.md, "Retry and removal"):
 *
 * - 2xx: the success is recorded.
 * - 404 or 410: the push service says the subscription is gone. It is
 *   removed once it has had no success for 24 hours; the draft asks not to
 *   remove on the first failure.
 * - 429, 5xx, a timeout or a connection error: tried again after 30
 *   seconds, 2 minutes and 10 minutes, then the message is dropped.
 * - Any other answer is not retried.
 * - A failing subscription with no success for 3 days is removed.
 * - The SSRF guard refusing the push resource removes it at once.
 *
 * A subscription removed or expired since the message was queued gets
 * nothing. A retired one (its collection was deleted) goes after its last
 * message, whatever the answer.
 */
class DeliverPushMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Seconds before the second, third and fourth try.
     */
    public const BACKOFF = [30, 120, 600];

    public const GONE_AFTER_HOURS = 24;

    public const FAILING_AFTER_DAYS = 3;

    public int $tries = 4;

    public int $timeout = 30;

    public function __construct(
        public int $subscriptionId,
        public string $topic,
        public string $message,
    ) {}

    public function handle(PushSender $sender): void
    {
        $subscription = PushSubscription::query()->find($this->subscriptionId);

        if (! $subscription instanceof PushSubscription || ! $subscription->expires_at->isFuture()) {
            return;
        }

        $result = $sender->send($subscription, $this->topic, $this->message);
        $context = ['subscription' => $subscription->id, 'host' => $subscription->endpointHost(), 'result' => $result->summary()];

        if ($result->kind === PushResult::SENT) {
            PushSubscription::query()->whereKey($subscription->id)->update(['last_success_at' => now()]);
            PushConfig::log('debug', 'Push message sent', $context);
        } elseif ($result->kind === PushResult::REFUSED) {
            $subscription->delete();
            PushConfig::log('warning', 'Push subscription removed: its push service address is not allowed', $context);

            return;
        } elseif ($result->kind === PushResult::RETRY && $this->attempts() < $this->tries) {
            $delay = self::BACKOFF[min($this->attempts(), count(self::BACKOFF)) - 1];
            PushConfig::log('info', 'Push message not delivered, trying again', $context + ['retry_in_seconds' => $delay]);
            $this->release($delay);

            return;
        } else {
            $this->recordFailure($subscription, $result, $context);
        }

        if ($subscription->removed_at !== null) {
            $subscription->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function recordFailure(PushSubscription $subscription, PushResult $result, array $context): void
    {
        $now = now();
        PushSubscription::query()->whereKey($subscription->id)->update([
            'last_failure_at' => $now,
            'last_failure' => $result->summary(),
        ]);

        $cutoff = $result->kind === PushResult::GONE
            ? $now->copy()->subHours(self::GONE_AFTER_HOURS)
            : $now->copy()->subDays(self::FAILING_AFTER_DAYS);
        $lastGood = $subscription->last_success_at ?? $subscription->created_at;

        if ($lastGood instanceof Carbon && $lastGood->lessThanOrEqualTo($cutoff)) {
            $subscription->delete();
            PushConfig::log('info', 'Push subscription removed: no successful push for too long', $context);

            return;
        }

        PushConfig::log('warning', 'Push message not delivered', $context);
    }
}
