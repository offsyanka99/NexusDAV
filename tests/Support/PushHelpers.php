<?php

namespace Tests\Support;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\Push\PushResult;
use App\Services\Push\PushSender;
use App\Services\UserService;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * DAV requests and WebDAV-Push registrations for the push tests.
 */
trait PushHelpers
{
    private function user(string $username): User
    {
        return app(UserService::class)->create([
            'username' => $username,
            'display_name' => ucfirst($username),
            'email' => $username.'@example.com',
        ]);
    }

    private function token(User $user): string
    {
        return app(AppPasswordService::class)->issue($user, 'phone')['token'];
    }

    /**
     * A push sender that answers 201 without the network, for tests that
     * run the queue synchronously.
     */
    private function fakeDelivery(): void
    {
        $this->app->instance(PushSender::class, new class implements PushSender
        {
            public function send(PushSubscription $subscription, string $topic, string $message): PushResult
            {
                return PushResult::fromStatus(201);
            }
        });
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

    private function body(TestResponse $response): string
    {
        return $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : (string) $response->getContent();
    }

    /**
     * A client key pair's public half (uncompressed P-256, base64url) and
     * a 16-byte auth secret, as a UnifiedPush distributor hands them out.
     *
     * @return array{0: string, 1: string}
     */
    private function clientKeys(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $this->assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        $point = "\x04".str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        return [self::base64Url($point), self::base64Url(random_bytes(16))];
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * @param  array{resource?: string, content?: string|null, property?: string|null, expires?: string|null, publicKey?: string, authSecret?: string, encoding?: string, keyType?: string}  $options
     */
    private function registerBody(array $options = []): string
    {
        [$publicKey, $authSecret] = $this->clientKeys();
        $resource = $options['resource'] ?? 'https://push.example.net/up/'.bin2hex(random_bytes(8));
        $content = array_key_exists('content', $options) ? $options['content'] : '1';
        $property = $options['property'] ?? null;
        $expires = $options['expires'] ?? null;

        $trigger = '';

        if ($content !== null) {
            $trigger .= '<P:content-update><D:depth>'.$content.'</D:depth></P:content-update>';
        }

        if ($property !== null) {
            $trigger .= '<P:property-update><D:depth>'.$property.'</D:depth><D:prop><D:displayname/></D:prop></P:property-update>';
        }

        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<P:push-register xmlns:D="DAV:" xmlns:P="https://bitfire.at/webdav-push">'
            .'<P:subscription><P:web-push-subscription>'
            .'<P:push-resource>'.htmlspecialchars($resource, ENT_XML1).'</P:push-resource>'
            .'<P:content-encoding>'.($options['encoding'] ?? 'aes128gcm').'</P:content-encoding>'
            .'<P:subscription-public-key type="'.($options['keyType'] ?? 'p256dh').'">'.($options['publicKey'] ?? $publicKey).'</P:subscription-public-key>'
            .'<P:auth-secret>'.($options['authSecret'] ?? $authSecret).'</P:auth-secret>'
            .'</P:web-push-subscription></P:subscription>'
            .'<P:trigger>'.$trigger.'</P:trigger>'
            .($expires === null ? '' : '<P:expires>'.$expires.'</P:expires>')
            .'</P:push-register>';
    }

    /**
     * @param  array{resource?: string, content?: string|null, property?: string|null, expires?: string|null, publicKey?: string, authSecret?: string, encoding?: string, keyType?: string}  $options
     */
    private function register(string $path, string $username, string $password, array $options = []): TestResponse
    {
        return $this->dav('POST', $path, $username, $password, ['Content-Type' => 'application/xml; charset="utf-8"'], $this->registerBody($options));
    }

    /**
     * Registers and returns the row.
     *
     * @param  array{resource?: string, content?: string|null, property?: string|null, expires?: string|null}  $options
     */
    private function subscribe(string $path, string $username, string $password, array $options = []): PushSubscription
    {
        $response = $this->register($path, $username, $password, $options);
        $this->assertSame(201, $response->getStatusCode(), $this->body($response));
        $location = (string) $response->headers->get('Location');

        return PushSubscription::query()->where('registration', substr($location, strrpos($location, '/') + 1))->firstOrFail();
    }

    private function propfind(string $properties): string
    {
        return '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:P="https://bitfire.at/webdav-push"><d:prop>'.$properties.'</d:prop></d:propfind>';
    }

    private function pushProps(): string
    {
        return $this->propfind('<P:transports/><P:topic/><P:supported-triggers/>');
    }

    private function topicOf(string $path, string $username, string $password): string
    {
        $body = $this->body($this->dav('PROPFIND', $path, $username, $password, ['Depth' => '0'], $this->propfind('<P:topic/>'))->assertStatus(207));
        $this->assertSame(1, preg_match('#<P:topic>([^<]+)</P:topic>#', $body, $match), $body);

        return $match[1];
    }

    private function event(string $uid, string $summary = 'Event'): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//NexusDAV//test//EN\r\nBEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20261102T130000Z\r\nSUMMARY:$summary\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    private function card(string $uid, string $name = 'Card'): string
    {
        return "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:$uid\r\nFN:$name\r\nN:$name;;;;\r\nEND:VCARD\r\n";
    }
}
