<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Sends a push message with Web Push (RFC 8030): encrypted for the client
 * (RFC 8291, aes128gcm) and signed with the server's VAPID key (RFC 8292,
 * subject APP_URL). The push resource goes through the SSRF guard first
 * and the request through PushHttpClient, pinned to the checked address.
 */
final class WebPushSender implements PushSender
{
    public const TTL_SECONDS = 86400;

    public function __construct(
        private PushEndpointGuard $guard,
        private PushClientFactory $clients,
        private PushKeys $keys,
    ) {}

    public function send(PushSubscription $subscription, string $topic, string $message): PushResult
    {
        try {
            $endpoint = $this->guard->check($subscription->push_resource);
        } catch (PushEndpointRefused $exception) {
            return PushResult::refused($exception->getMessage());
        } catch (PushEndpointUnresolved $exception) {
            return PushResult::retry($exception->getMessage());
        }

        try {
            $factory = new HttpFactory;
            $webPush = new WebPush(
                ['VAPID' => [
                    'subject' => rtrim((string) config('app.url'), '/'),
                    'publicKey' => $this->keys->vapidPublicKey(),
                    'privateKey' => $this->keys->vapidPrivateKey(),
                ]],
                [
                    'TTL' => self::TTL_SECONDS,
                    'urgency' => 'normal',
                    'contentType' => 'application/xml; charset="UTF-8"',
                ],
                $this->clients->make($endpoint),
                $factory,
                $factory,
                null,
                Log::getLogger(),
            );

            $report = $webPush->sendOneNotification(
                new Subscription($subscription->push_resource, $subscription->public_key, $subscription->auth_secret, 'aes128gcm'),
                $message,
                ['topic' => $topic],
            );
        } catch (Throwable $exception) {
            // An encryption error: the stored keys cannot be used.
            return PushResult::failed('The message could not be encrypted ('.class_basename($exception).').');
        }

        $response = $report->getResponse();

        if ($response === null) {
            return PushResult::retry($report->getReason());
        }

        return PushResult::fromStatus($response->getStatusCode(), $response->getReasonPhrase());
    }
}
