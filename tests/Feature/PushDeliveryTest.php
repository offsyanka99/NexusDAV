<?php

namespace Tests\Feature;

use App\Jobs\DeliverPushMessage;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Push\PushClientFactory;
use App\Services\Push\PushEndpoint;
use App\Services\Push\PushEndpointGuard;
use App\Services\Push\PushEndpointRefused;
use App\Services\Push\PushEndpointUnresolved;
use App\Services\Push\PushHttpClient;
use App\Services\Push\PushKeys;
use App\Services\Push\PushNetworkError;
use App\Services\Push\PushResolver;
use App\Services\Push\PushSender;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use OpenSSLAsymmetricKey;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Process\Process;
use Tests\Support\PushHelpers;
use Tests\TestCase;

/**
 * PR 37: Web Push delivery, the SSRF guard, and retry and removal
 * (docs/design/webdav-push.md, "Sending").
 */
class PushDeliveryTest extends TestCase
{
    use PushHelpers, RefreshDatabase;

    private const RESOURCE = 'https://push.example.net/up/Secret-Path-4711';

    private User $ada;

    private string $adaToken;

    /**
     * @var array<string, list<string>>
     */
    private array $dns = [
        'push.example.net' => ['93.184.216.34'],
        'ntfy.example.org' => ['2606:4700::6810:85e5'],
        'localhost.example.com' => ['127.0.0.1'],
        'mixed.example.com' => ['93.184.216.34', '10.0.0.7'],
        'metadata.example.com' => ['169.254.169.254'],
        'v6-loopback.example.com' => ['::1'],
    ];

    /**
     * @var list<RequestInterface>
     */
    private array $requests = [];

    /**
     * @var list<PushEndpoint>
     */
    private array $endpoints = [];

    /**
     * @var list<ResponseInterface|\Throwable>
     */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['nexusdav.features.push' => true, 'app.url' => 'https://dav.example.org']);

        $this->ada = $this->user('ada');
        $this->adaToken = $this->token($this->ada);

        $test = $this;
        $this->app->instance(PushResolver::class, new class($test) implements PushResolver
        {
            public function __construct(private PushDeliveryTest $test) {}

            public function addresses(string $host): array
            {
                return $this->test->resolve($host);
            }
        });
        $this->app->instance(PushClientFactory::class, new class($test) extends PushClientFactory
        {
            public function __construct(private PushDeliveryTest $test) {}

            public function make(PushEndpoint $endpoint): ClientInterface
            {
                return $this->test->fakePushService($endpoint);
            }
        });
    }

    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        return $this->dns[$host] ?? [];
    }

    public function fakePushService(PushEndpoint $endpoint): ClientInterface
    {
        $this->endpoints[] = $endpoint;
        $test = $this;

        return new class($test) implements ClientInterface
        {
            public function __construct(private PushDeliveryTest $test) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->test->answer($request);
            }
        };
    }

    public function answer(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $answer = array_shift($this->answers) ?? new Response(201);

        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        return $answer;
    }

    public function test_a_message_is_encrypted_for_the_client_and_signed_with_vapid(): void
    {
        [$subscription, $clientKey] = $this->subscription();
        $message = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<P:push-message xmlns:D="DAV:" xmlns:P="https://bitfire.at/webdav-push"><P:topic>'.$subscription->topic.'</P:topic><P:content-update/></P:push-message>'."\n";

        $this->deliver($subscription, $message);

        $this->assertCount(1, $this->requests);
        $request = $this->requests[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(self::RESOURCE, (string) $request->getUri());
        $this->assertSame('aes128gcm', $request->getHeaderLine('Content-Encoding'));
        $this->assertSame('application/xml; charset="UTF-8"', $request->getHeaderLine('Content-Type'));
        $this->assertSame('86400', $request->getHeaderLine('TTL'));
        $this->assertSame('normal', $request->getHeaderLine('Urgency'));
        $this->assertSame($subscription->topic, $request->getHeaderLine('Topic'));

        // The connection is pinned to the address the guard checked.
        $this->assertSame('93.184.216.34', $this->endpoints[0]->address);
        $this->assertSame('push.example.net:443:93.184.216.34', $this->endpoints[0]->resolveEntry());

        $body = (string) $request->getBody();
        $this->assertStringNotContainsString('push-message', $body);
        $this->assertSame($message, $this->decrypt($body, $clientKey, $subscription->auth_secret));

        $this->assertVapid($request->getHeaderLine('Authorization'));
        $this->assertNotNull($subscription->fresh()?->last_success_at);
    }

    public function test_the_ssrf_guard_refuses_private_and_reserved_addresses(): void
    {
        $guard = app(PushEndpointGuard::class);
        $refused = [
            'http://push.example.net/up',
            'https://user:pw@push.example.net/up',
            'https://127.0.0.1/up',
            'https://10.1.2.3/up',
            'https://172.16.0.1/up',
            'https://192.168.1.10/up',
            'https://169.254.169.254/latest/meta-data',
            'https://100.64.0.1/up',
            'https://0.0.0.0/up',
            'https://224.0.0.1/up',
            'https://255.255.255.255/up',
            'https://198.18.0.1/up',
            'https://[::1]/up',
            'https://[::]/up',
            'https://[fe80::1]/up',
            'https://[fc00::1]/up',
            'https://[fd12:3456::1]/up',
            'https://[ff02::1]/up',
            'https://[::ffff:127.0.0.1]/up',
            'https://[::ffff:10.0.0.1]/up',
            'https://[64:ff9b::a00:1]/up',
            'https://[2002:7f00:1::1]/up',
            'https://[2001::1]/up',
            'https://[2001:db8::1]/up',
            'https://localhost.example.com/up',
            'https://mixed.example.com/up',
            'https://metadata.example.com/up',
            'https://v6-loopback.example.com/up',
            'https://bad_host!.example/up',
        ];

        foreach ($refused as $url) {
            try {
                $guard->check($url);
                $this->fail('The guard allowed '.$url);
            } catch (PushEndpointRefused $exception) {
                $this->assertStringNotContainsString('/up', $exception->getMessage());
            }
        }

        $allowed = [
            'https://push.example.net/up' => '93.184.216.34',
            'https://ntfy.example.org:8443/up' => '2606:4700::6810:85e5',
            'https://8.8.8.8/up' => '8.8.8.8',
            'https://[2606:4700:4700::1111]/up' => '2606:4700:4700::1111',
            'https://[64:ff9b::808:808]/up' => '64:ff9b::808:808',
            'https://[::ffff:8.8.4.4]/up' => '::ffff:8.8.4.4',
        ];

        foreach ($allowed as $url => $address) {
            $this->assertSame($address, $guard->check($url)->address, $url);
        }

        $this->assertSame(8443, $guard->check('https://ntfy.example.org:8443/up')->port);
        $this->assertSame('ntfy.example.org:8443:[2606:4700::6810:85e5]', $guard->check('https://ntfy.example.org:8443/up')->resolveEntry());

        $this->expectException(PushEndpointUnresolved::class);
        $guard->check('https://unknown.example.com/up');
    }

    public function test_private_networks_and_plain_http_are_allowed_when_switched_on(): void
    {
        config(['nexusdav.push.allow_private' => true]);
        $guard = app(PushEndpointGuard::class);

        $this->assertSame('10.1.2.3', $guard->check('http://10.1.2.3:8080/up')->address);
        $this->assertSame(8080, $guard->check('http://10.1.2.3:8080/up')->port);
        $this->assertSame('127.0.0.1', $guard->check('https://localhost.example.com/up')->address);

        $this->expectException(PushEndpointRefused::class);
        $guard->check('ftp://10.1.2.3/up');
    }

    public function test_a_refused_push_resource_removes_the_subscription_at_once(): void
    {
        [$subscription] = $this->subscription('https://metadata.example.com/latest/meta-data');

        $this->deliver($subscription);

        $this->assertSame([], $this->requests);
        $this->assertNull($subscription->fresh());
    }

    public function test_the_pinned_client_follows_no_redirect_and_reads_at_most_64_kb(): void
    {
        $root = sys_get_temp_dir().'/nexusdav-push-server-'.bin2hex(random_bytes(6));
        mkdir($root);
        file_put_contents($root.'/router.php', <<<'PHP'
            <?php
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            file_put_contents(__DIR__.'/hits.log', $path.' '.($_SERVER['HTTP_HOST'] ?? '')."\n", FILE_APPEND);
            if ($path === '/redirect') { header('Location: /target', true, 301); exit; }
            if ($path === '/big') { echo str_repeat('x', 1048576); exit; }
            http_response_code(201);
            PHP);

        $port = $this->freePort();
        $server = new Process([PHP_BINARY, '-S', '127.0.0.1:'.$port, 'router.php'], $root);
        $server->start();

        try {
            $this->waitForPort($port);
            // A made-up name: the connection can only come from the pin.
            $client = new PushHttpClient(new PushEndpoint('http', 'push.invalid', $port, '127.0.0.1'));

            $this->assertSame(201, $client->sendRequest(new Request('POST', 'http://push.invalid:'.$port.'/ok', [], 'x'))->getStatusCode());

            $redirect = $client->sendRequest(new Request('POST', 'http://push.invalid:'.$port.'/redirect', [], 'x'));
            $this->assertSame(301, $redirect->getStatusCode());

            $big = $client->sendRequest(new Request('POST', 'http://push.invalid:'.$port.'/big', [], 'x'));
            $this->assertSame(200, $big->getStatusCode());
            $this->assertSame(PushHttpClient::MAX_BODY_BYTES, strlen((string) $big->getBody()));

            $hits = (string) file_get_contents($root.'/hits.log');
            $this->assertStringContainsString('/redirect push.invalid:'.$port, $hits);
            $this->assertStringNotContainsString('/target', $hits);

            try {
                $client->sendRequest(new Request('POST', 'http://elsewhere.invalid:'.$port.'/ok', [], 'x'));
                $this->fail('The client sent to a host it was not checked for.');
            } catch (PushNetworkError) {
                // Expected.
            }
        } finally {
            $server->stop(1);
            array_map('unlink', glob($root.'/*') ?: []);
            rmdir($root);
        }

        // Nothing listens there now: a network error without the URL.
        try {
            (new PushHttpClient(new PushEndpoint('http', 'push.invalid', $port, '127.0.0.1')))
                ->sendRequest(new Request('POST', 'http://push.invalid:'.$port.'/secret-path', [], 'x'));
            $this->fail('The request to a closed port did not fail.');
        } catch (PushNetworkError $exception) {
            $this->assertStringNotContainsString('secret-path', $exception->getMessage());
            $this->assertStringNotContainsString('push.invalid', $exception->getMessage());
        }
    }

    public function test_a_gone_subscription_is_removed_after_24_hours_without_success(): void
    {
        [$fresh] = $this->subscription();
        $this->answers = [new Response(410)];
        $this->deliver($fresh);
        $this->assertSame('410 Gone', $fresh->fresh()?->last_failure);
        $this->assertNotNull($fresh->fresh()?->last_failure_at);

        [$recent] = $this->subscription(self::RESOURCE.'/recent');
        $recent->forceFill(['created_at' => now()->subDays(5), 'last_success_at' => now()->subHours(2)])->save();
        $this->answers = [new Response(404)];
        $this->deliver($recent);
        $this->assertNotNull($recent->fresh());

        [$stale] = $this->subscription(self::RESOURCE.'/stale');
        $stale->forceFill(['created_at' => now()->subDays(5), 'last_success_at' => now()->subHours(25)])->save();
        $this->answers = [new Response(410)];
        $this->deliver($stale);
        $this->assertNull($stale->fresh());

        [$never] = $this->subscription(self::RESOURCE.'/never');
        $never->forceFill(['created_at' => now()->subHours(25)])->save();
        $this->answers = [new Response(404)];
        $this->deliver($never);
        $this->assertNull($never->fresh());
    }

    public function test_429_5xx_and_network_errors_are_retried_with_backoff(): void
    {
        [$subscription] = $this->subscription();

        foreach ([1 => 30, 2 => 120, 3 => 600] as $attempt => $delay) {
            $this->answers = [$attempt === 2 ? new PushNetworkError(new Request('POST', self::RESOURCE), 'Timeout was reached') : new Response($attempt === 1 ? 429 : 503)];
            $job = $this->job($subscription, $attempt);
            $job->handle(app(PushSender::class));
            $job->assertReleased($delay);
        }

        $this->assertNull($subscription->fresh()?->last_failure_at);

        // The fourth try is the last: the failure is recorded, nothing is released.
        $this->answers = [new Response(502, [], '', '1.1', 'Bad Gateway')];
        $job = $this->job($subscription, 4);
        $job->handle(app(PushSender::class));
        $job->assertNotReleased();
        $this->assertSame('502 Bad Gateway', $subscription->fresh()?->last_failure);
        $this->assertNotNull($subscription->fresh());
        $this->assertCount(4, $this->requests);
    }

    public function test_other_answers_are_not_retried_and_a_failing_subscription_goes_after_three_days(): void
    {
        [$subscription] = $this->subscription();
        $this->answers = [new Response(400, [], '', '1.1', "Bad Request \u{00FC}\u{00FF}")];
        $job = $this->job($subscription, 1);
        $job->handle(app(PushSender::class));
        $job->assertNotReleased();
        $this->assertSame('400 Bad Request', $subscription->fresh()?->last_failure);

        $subscription->forceFill(['created_at' => now()->subDays(4), 'last_success_at' => now()->subDays(3)->subMinute()])->save();
        $this->answers = [new Response(413)];
        $this->deliver($subscription);
        $this->assertNull($subscription->fresh());

        [$healthy] = $this->subscription(self::RESOURCE.'/healthy');
        $healthy->forceFill(['created_at' => now()->subDays(10), 'last_success_at' => now()->subDay()])->save();
        $this->answers = [new Response(500)];
        $this->job($healthy, 4)->handle(app(PushSender::class));
        $this->assertNotNull($healthy->fresh());

        // A redirect is an answer like any other: not followed, not retried.
        $this->answers = [new Response(301, ['Location' => 'https://10.0.0.1/']), new Response(201)];
        $before = count($this->requests);
        $redirect = $this->job($healthy, 1);
        $redirect->handle(app(PushSender::class));
        $redirect->assertNotReleased();
        $this->assertCount($before + 1, $this->requests);
        $this->assertStringStartsWith('301', (string) $healthy->fresh()?->last_failure);
    }

    public function test_a_retired_subscription_goes_after_its_last_message(): void
    {
        [$sent] = $this->subscription();
        $sent->forceFill(['removed_at' => now()])->save();
        $this->deliver($sent);
        $this->assertNull($sent->fresh());

        [$failing] = $this->subscription(self::RESOURCE.'/failing');
        $failing->forceFill(['removed_at' => now()])->save();
        $this->answers = [new Response(503)];
        $job = $this->job($failing, 1);
        $job->handle(app(PushSender::class));
        $job->assertReleased(30);
        $this->assertNotNull($failing->fresh());

        $this->answers = [new Response(503)];
        $this->job($failing, 4)->handle(app(PushSender::class));
        $this->assertNull($failing->fresh());
    }

    public function test_a_dav_change_reaches_the_push_service_with_the_sync_token(): void
    {
        [$subscription, $clientKey] = $this->subscription();

        $this->dav('PUT', '/dav/calendars/ada/default/e.ics', 'ada', $this->adaToken, ['Content-Type' => 'text/calendar'], $this->event('e'))->assertCreated();

        $this->assertCount(1, $this->requests);
        $message = $this->decrypt((string) $this->requests[0]->getBody(), $clientKey, $subscription->auth_secret);
        $props = $this->body($this->dav('PROPFIND', '/dav/calendars/ada/default/', 'ada', $this->adaToken, ['Depth' => '0'], '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:sync-token/></d:prop></d:propfind>'));
        $this->assertSame(1, preg_match('#<d:sync-token>([^<]+)</d:sync-token>#', $props, $token));
        $this->assertStringContainsString('<P:topic>'.$subscription->topic.'</P:topic>', $message);
        $this->assertStringContainsString('<D:sync-token>'.$token[1].'</D:sync-token>', $message);
    }

    public function test_no_url_key_or_secret_reaches_the_log(): void
    {
        config(['nexusdav.push.log_level' => 'debug']);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->level.' '.$event->message.' '.json_encode($event->context);
        });

        [$subscription] = $this->subscription();
        [$refused] = $this->subscription('https://localhost.example.com/up/Secret-Path-4711');
        $this->answers = [
            new Response(201),
            new Response(503),
            new PushNetworkError(new Request('POST', self::RESOURCE), 'Could not connect to server'),
            new Response(410),
        ];

        $this->deliver($subscription);
        $this->job($subscription, 1)->handle(app(PushSender::class));
        $this->job($subscription, 4)->handle(app(PushSender::class));
        $subscription->forceFill(['created_at' => now()->subDays(2)])->save();
        $this->deliver($subscription->fresh() ?? $subscription);
        $this->deliver($refused);

        $text = implode("\n", $logged);
        $this->assertStringContainsString('Push message sent', $text);
        $this->assertStringContainsString('trying again', $text);
        $this->assertStringContainsString('push service address is not allowed', $text);
        $this->assertStringContainsString('push.example.net', $text);

        foreach ([
            'Secret-Path-4711',
            $subscription->public_key,
            $subscription->auth_secret,
            app(PushKeys::class)->vapidPrivateKey(),
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
    }

    /**
     * @return array{0: PushSubscription, 1: OpenSSLAsymmetricKey}
     */
    private function subscription(string $resource = self::RESOURCE): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $this->assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        $point = "\x04".str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        $response = $this->register('/dav/calendars/ada/default/', 'ada', $this->adaToken, [
            'resource' => $resource,
            'publicKey' => self::base64Url($point),
            'authSecret' => self::base64Url(random_bytes(16)),
        ]);

        if ($response->getStatusCode() !== 201) {
            // The guard runs at delivery, not at registration: a resource
            // that registration refuses is written directly.
            $subscription = PushSubscription::query()->create([
                'registration' => substr(bin2hex(random_bytes(16)), 0, 32),
                'user_id' => $this->ada->getKey(),
                'scope' => hash('sha256', $resource),
                'path' => 'calendars/ada/default',
                'topic' => $this->topicOf('/dav/calendars/ada/default/', 'ada', $this->adaToken),
                'push_resource' => $resource,
                'public_key' => self::base64Url($point),
                'auth_secret' => self::base64Url(random_bytes(16)),
                'content_update_depth' => '1',
                'expires_at' => now()->addDay(),
            ]);

            return [$subscription, $key];
        }

        $location = (string) $response->headers->get('Location');

        return [PushSubscription::query()->where('registration', substr($location, strrpos($location, '/') + 1))->firstOrFail(), $key];
    }

    private function job(PushSubscription $subscription, int $attempt, string $message = '<m/>'): DeliverPushMessage
    {
        $job = (new DeliverPushMessage($subscription->id, $subscription->topic, $message))->withFakeQueueInteractions();
        $job->job->attempts = $attempt;

        return $job;
    }

    private function deliver(PushSubscription $subscription, string $message = '<m/>'): void
    {
        $this->job($subscription, 1, $message)->handle(app(PushSender::class));
    }

    /**
     * RFC 8291 and RFC 8188 (aes128gcm), as a UnifiedPush client decrypts.
     */
    private function decrypt(string $body, OpenSSLAsymmetricKey $clientKey, string $authSecret): string
    {
        $salt = substr($body, 0, 16);
        $keyLength = ord($body[20]);
        $serverPublic = substr($body, 21, $keyLength);
        $cipherText = substr($body, 21 + $keyLength);
        $this->assertSame(65, $keyLength);

        $details = openssl_pkey_get_details($clientKey);
        $this->assertIsArray($details);
        $clientPublic = "\x04".str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $shared = openssl_pkey_derive($this->publicKey($serverPublic), $clientKey, 32);
        $this->assertIsString($shared);

        $auth = (string) base64_decode(strtr($authSecret, '-_', '+/'), true);
        $prkKey = hash_hmac('sha256', $shared, $auth, true);
        $ikm = hash_hmac('sha256', "WebPush: info\x00".$clientPublic.$serverPublic."\x01", $prkKey, true);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\x00\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\x00\x01", $prk, true), 0, 12);

        $plain = openssl_decrypt(substr($cipherText, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($cipherText, -16));
        $this->assertIsString($plain, 'The message does not decrypt with the client key.');

        // The last record ends with the 0x02 delimiter, then zero padding.
        $plain = rtrim($plain, "\x00");
        $this->assertSame("\x02", substr($plain, -1));

        return substr($plain, 0, -1);
    }

    private function assertVapid(string $authorization): void
    {
        $this->assertSame(1, preg_match('/^vapid t=([^,]+), k=([A-Za-z0-9_-]+)$/', $authorization, $match), $authorization);
        $this->assertSame(app(PushKeys::class)->vapidPublicKey(), $match[2]);

        [$header, $payload, $signature] = explode('.', $match[1]);
        $this->assertSame(['typ' => 'JWT', 'alg' => 'ES256'], json_decode($this->unbase64($header), true));
        $claims = json_decode($this->unbase64($payload), true);
        $this->assertSame('https://push.example.net', $claims['aud']);
        $this->assertSame('https://dav.example.org', $claims['sub']);
        $this->assertGreaterThan(time(), $claims['exp']);
        $this->assertLessThanOrEqual(time() + 86400, $claims['exp']);

        $raw = $this->unbase64($signature);
        $this->assertSame(64, strlen($raw));
        $der = $this->derSignature(substr($raw, 0, 32), substr($raw, 32));
        $this->assertSame(1, openssl_verify($header.'.'.$payload, $der, $this->publicKey($this->unbase64($match[2])), OPENSSL_ALGO_SHA256));
    }

    private function publicKey(string $point): OpenSSLAsymmetricKey
    {
        $der = (string) hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$point;
        $key = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n");
        $this->assertInstanceOf(OpenSSLAsymmetricKey::class, $key);

        return $key;
    }

    private function derSignature(string $r, string $s): string
    {
        $integer = static function (string $value): string {
            $value = ltrim($value, "\x00");

            if ($value === '' || ord($value[0]) > 0x7F) {
                $value = "\x00".$value;
            }

            return "\x02".chr(strlen($value)).$value;
        };
        $sequence = $integer($r).$integer($s);

        return "\x30".chr(strlen($sequence)).$sequence;
    }

    private function unbase64(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function waitForPort(int $port): void
    {
        for ($i = 0; $i < 50; $i++) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);

            if ($connection !== false) {
                fclose($connection);

                return;
            }

            usleep(100_000);
        }

        $this->fail('The test push service did not start.');
    }
}
