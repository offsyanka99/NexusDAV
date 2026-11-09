<?php

namespace App\Services\Push;

use App\Models\PushSubscription;

/**
 * Sends one push message to one subscription.
 */
interface PushSender
{
    public function send(PushSubscription $subscription, string $topic, string $message): PushResult;
}
