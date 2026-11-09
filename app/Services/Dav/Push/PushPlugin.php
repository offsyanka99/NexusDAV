<?php

namespace App\Services\Dav\Push;

use App\Models\User;
use App\Services\Dav\DavAuthBackend;
use App\Services\Dav\DavServerFactory;
use App\Services\Push\PushChanges;
use App\Services\Push\PushConfig;
use App\Services\Push\PushKeys;
use App\Services\Push\PushSubscriptions;
use App\Services\Push\PushTopics;
use Sabre\DAV\Exception\MethodNotAllowed;
use Sabre\DAV\Exception\NotAuthenticated;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\INode;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * WebDAV-Push (draft-bitfire-webdav-push-00, docs/design/webdav-push.md).
 *
 * Discovery: `webdav-push` in the DAV header and the transports, topic and
 * supported-triggers properties on push-capable collections. Registration:
 * a push-register POST to the collection; DELETE on /dav/push/<id> removes
 * it. The plugin is always loaded, so a registration while push is off
 * answers push-not-available instead of 501. It also reads
 * Push-Dont-Notify and reports file changes to PushChanges.
 */
final class PushPlugin extends ServerPlugin
{
    public const NS = 'https://bitfire.at/webdav-push';

    private const PROPERTIES = ['{'.self::NS.'}transports', '{'.self::NS.'}topic', '{'.self::NS.'}supported-triggers'];

    /**
     * After the auth plugin (10), before the ACL plugin (20), which would
     * look for a node at /dav/push/<id>.
     */
    private const BEFORE_METHOD_PRIORITY = 15;

    /**
     * Before the POST handler that refuses client sharing requests (100).
     */
    private const POST_PRIORITY = 90;

    private Server $server;

    private ?User $user = null;

    public function __construct(
        private DavAuthBackend $auth,
        private PushTopics $topics,
        private PushSubscriptions $subscriptions,
        private PushChanges $changes,
        private PushKeys $keys,
        private PushRegisterParser $parser,
    ) {}

    public function getPluginName()
    {
        return 'webdav-push';
    }

    /**
     * @return list<string>
     */
    public function getFeatures()
    {
        return PushConfig::enabled() ? ['webdav-push'] : [];
    }

    public function initialize(Server $server)
    {
        $this->server = $server;
        $server->xml->namespaceMap[self::NS] = 'P';

        $server->on('beforeMethod:*', [$this, 'beforeMethod'], self::BEFORE_METHOD_PRIORITY);
        $server->on('method:POST', [$this, 'httpPost'], self::POST_PRIORITY);
        $server->on('propFind', [$this, 'propFind']);
        $server->on('afterBind', function (string $path): void {
            $this->fileChanged($path, false);
        });
        $server->on('afterWriteContent', function (string $path): void {
            $this->fileChanged($path, false);
        });
        $server->on('afterUnbind', function (string $path): void {
            $this->fileChanged($path, true);
        });
    }

    public function beforeMethod(RequestInterface $request, ResponseInterface $response): bool
    {
        $this->user = null;
        $this->readDontNotify($request);

        $path = trim($request->getPath(), '/');

        if ($path !== 'push' && ! str_starts_with($path, 'push/')) {
            return true;
        }

        if (strtoupper($request->getMethod()) !== 'DELETE') {
            throw new class('A push registration can only be removed with DELETE.') extends MethodNotAllowed
            {
                public function getHTTPHeaders(Server $server)
                {
                    return ['Allow' => 'DELETE'];
                }
            };
        }

        $registration = substr($path, strlen('push/'));
        $user = $this->user();

        if (! $user instanceof User
            || preg_match('/^[A-Za-z0-9_-]{32}$/', $registration) !== 1
            || ! $this->subscriptions->unregister($user, $registration)) {
            throw new NotFound('This push registration does not exist.');
        }

        PushConfig::log('info', 'Push subscription removed', ['user' => $user->username]);
        $response->setStatus(204);
        $response->setHeader('Content-Length', '0');

        return false;
    }

    public function httpPost(RequestInterface $request, ResponseInterface $response): bool
    {
        if (! str_contains(strtolower((string) $request->getHeader('Content-Type')), 'xml')) {
            return true;
        }

        $body = $request->getBodyAsString();
        $request->setBody($body);
        $root = $this->parser->document($body);

        if ($root === null) {
            return true;
        }

        if (! PushConfig::enabled()) {
            throw new PushPrecondition(PushPrecondition::NOT_AVAILABLE, 'WebDAV-Push is turned off on this server.');
        }

        $path = $request->getPath();
        $capability = $this->topics->capability($path, $this->server->tree->getNodeForPath($path));

        if ($capability === null) {
            throw new PushPrecondition(PushPrecondition::NOT_AVAILABLE, 'WebDAV-Push is not available for this resource.');
        }

        $registration = $this->parser->registration($root);
        $user = $this->user();

        if (! $user instanceof User) {
            throw new NotAuthenticated('No user is signed in.');
        }

        [$subscription, $created] = $this->subscriptions->register($user, $this->auth->appPasswordId(), $capability, $registration);

        PushConfig::log('info', $created ? 'Push subscription registered' : 'Push subscription renewed', [
            'user' => $user->username,
            'path' => $capability->path,
            'host' => $subscription->endpointHost(),
            'expires' => $subscription->expires_at->toIso8601String(),
        ]);

        $response->setStatus($created ? 201 : 204);
        $response->setHeader('Location', $this->subscriptions->registrationUrl($subscription->registration));
        $response->setHeader('Expires', $subscription->expires_at->copy()->utc()->format('D, d M Y H:i:s \G\M\T'));
        $response->setHeader('Content-Length', '0');

        return false;
    }

    public function propFind(PropFind $propFind, INode $node): void
    {
        if (! PushConfig::enabled() || ! $this->requested($propFind)) {
            return;
        }

        $capability = $this->topics->capability($propFind->getPath(), $node);

        if ($capability === null) {
            return;
        }

        $propFind->handle(self::PROPERTIES[0], fn (): Transports => new Transports($this->keys->vapidPublicKey()));
        $propFind->handle(self::PROPERTIES[1], $capability->topic);
        $propFind->handle(self::PROPERTIES[2], fn (): SupportedTriggers => new SupportedTriggers($capability->contentDepth, $capability->propertyDepth));
    }

    private function requested(PropFind $propFind): bool
    {
        foreach (self::PROPERTIES as $property) {
            if ($propFind->getStatus($property) === 404) {
                return true;
            }
        }

        return false;
    }

    /**
     * `*` alone, or a list of quoted registration URLs. Values that are not
     * this user's registrations are ignored.
     */
    private function readDontNotify(RequestInterface $request): void
    {
        $values = $request->getHeaderAsArray('Push-Dont-Notify');

        if ($values === []) {
            $this->changes->reset();

            return;
        }

        $joined = trim(implode(',', $values));

        if ($joined === '*') {
            $this->changes->dontNotify(true, []);

            return;
        }

        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $joined, $matches);
        $urls = array_map(static fn (string $url): string => (string) preg_replace('/\\\\(.)/s', '$1', $url), $matches[1]);
        $user = $this->user();

        $this->changes->dontNotify(false, $user instanceof User && $urls !== [] ? $this->subscriptions->registrationsOf((int) $user->getKey(), $urls) : []);
    }

    private function fileChanged(string $path, bool $removed): void
    {
        if (DavServerFactory::underFiles($path)) {
            $this->changes->fileChanged($path, $removed);
        }
    }

    private function user(): ?User
    {
        if ($this->user instanceof User) {
            return $this->user;
        }

        $username = $this->auth->username();

        if (! is_string($username) || $username === '') {
            return null;
        }

        return $this->user = User::query()->where('username', $username)->first();
    }
}
