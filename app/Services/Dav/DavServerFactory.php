<?php

namespace App\Services\Dav;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Sabre\CalDAV\Plugin as CalDavPlugin;
use Sabre\DAV\Auth\Plugin as AuthPlugin;
use Sabre\DAV\Exception as DavException;
use Sabre\DAV\Exception\Conflict;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Locks\Backend\PDO as LockBackend;
use Sabre\DAV\Locks\Plugin as LockPlugin;
use Sabre\DAV\PropertyStorage\Plugin as PropertyPlugin;
use Sabre\DAV\Server;
use Sabre\DAV\Sync\Plugin as SyncPlugin;
use Sabre\DAVACL\Plugin as AclPlugin;
use Sabre\DAVACL\PrincipalCollection;
use Sabre\HTTP\RequestInterface;
use Throwable;

/**
 * Builds the sabre tree and plugins in one place.
 * Auth and owner-only DAVACL are loaded here.
 */
final class DavServerFactory
{
    public function __construct(private DavAuthBackend $auth) {}

    public function make(): Server
    {
        $pdo = DB::connection()->getPdo();
        $principals = new OwnerOnlyPrincipalBackend(new PrincipalBackend, $this->auth);
        $nodes = [
            new PrincipalCollection($principals, 'principals'),
        ];

        if ($this->enabled('caldav')) {
            $nodes[] = new CalendarRoot($principals, new CalendarBackend($pdo), 'principals');
        }

        if ($this->enabled('carddav')) {
            $nodes[] = new AddressBookRoot($principals, new AddressBookBackend($pdo), 'principals');
        }

        $files = app(FileStorage::class);

        if ($this->enabled('files')) {
            $nodes[] = new FilesRoot($principals, $files, 'principals');
        }

        $server = new Server($nodes);
        $server->setBaseUri('/dav/');

        $server->addPlugin(new AuthPlugin($this->auth));

        $acl = new AclPlugin;
        $acl->allowUnauthenticatedAccess = false;
        $acl->hideNodesFromListings = true;
        $acl->adminPrincipals = [];
        // CalendarRoot, AddressBookRoot, and the server root do not implement
        // IACL. sabre's default grants them {DAV:}all, so a depth-infinity LOCK
        // of those roots would make another user's PUT return 423. {DAV:}read
        // does not include write-content. Homes and calendars keep their ACLs.
        $acl->setDefaultAcl([
            [
                'principal' => '{DAV:}authenticated',
                'privilege' => '{DAV:}read',
                'protected' => true,
            ],
        ]);
        $server->addPlugin($acl);

        if ($this->enabled('caldav') || $this->enabled('carddav')) {
            $server->addPlugin(new SyncPlugin);
        }

        if ($this->enabled('caldav')) {
            $server->addPlugin(new CalDavPlugin);
        }

        if ($this->enabled('carddav')) {
            $server->addPlugin(new CardDavPlugin);
        }

        $server->addPlugin(new LockPlugin(new LockBackend($pdo)));
        // Dead properties move with MOVE and go with DELETE in sabre's
        // plugin; COPY is added here. Locks stay with their URL: a MOVE
        // releases the source's locks (RFC 4918, 9.9.4). sabre's
        // TemporaryFileFilterPlugin is not loaded: it would keep ._*,
        // .DS_Store and editor swap files outside the data volume with no
        // cleanup. In a file home they are ordinary files.
        $properties = new PropertyStorageBackend($pdo);
        $server->addPlugin(new PropertyPlugin($properties));
        $server->on('afterCopy', function (string $source, string $destination, int $depth) use ($properties): void {
            $properties->copy($source, $destination, $depth);
        });

        // PROPFIND and REPORT are not privilege-checked by sabre's ACL plugin.
        // A calendar-query would otherwise return another user's events.
        $server->on('beforeMethod:PROPFIND', function (RequestInterface $request) use ($server): void {
            $this->requireReadIfPresent($server, $request);
        });
        $server->on('beforeMethod:REPORT', function (RequestInterface $request) use ($server): void {
            $this->requireReadIfPresent($server, $request);
        });

        // A switched-off service answers 403 with a reason, not 404. Its root
        // and plugin are not loaded, and the stored data is kept.
        $server->on('beforeMethod:*', function (RequestInterface $request) use ($server, $files): void {
            $this->refuseDisabledService($request);
            $this->refuseOtherHomes($server, $request);
            $this->refuseCopyAcrossFiles($server, $request);
            $this->limitUpload($files, $request);
        });

        // Shares are created in the admin panel. sabre's sharing plugins are
        // not loaded; a client share or invite-reply is still refused.
        $server->on('method:POST', function (RequestInterface $request): void {
            if ($this->clientShareRequest($request)) {
                throw new Forbidden('Sharing is not available from a client.');
            }
        });

        // sabre turns any exception into a response. Its own DAV exceptions
        // are expected client errors; anything else is a server bug that
        // would otherwise leave no trace. DavController logs a busy
        // database itself, once it has retried or given up.
        $server->on('exception', function (Throwable $exception): void {
            if (! $exception instanceof DavException && ! BusyDatabase::caused($exception)) {
                Log::error('DAV request failed', ['exception' => $exception]);
            }
        });

        return $server;
    }

    private function clientShareRequest(RequestInterface $request): bool
    {
        $type = strtolower((string) $request->getHeader('Content-Type'));

        if (str_contains($type, 'application/davsharing+xml')) {
            return true;
        }

        if (! str_contains($type, 'xml')) {
            return false;
        }

        $body = $request->getBodyAsString();
        $request->setBody($body);

        return preg_match('/<(?:[A-Za-z0-9]+:)?(?:share-resource|invite-reply|publish-calendar|unpublish-calendar|share)\b/', $body) === 1;
    }

    private function refuseDisabledService(RequestInterface $request): void
    {
        $root = explode('/', trim($request->getPath(), '/'), 2)[0];

        if ($root === 'calendars' && ! $this->enabled('caldav')) {
            throw new Forbidden('CalDAV is turned off on this server.');
        }

        if ($root === 'addressbooks' && ! $this->enabled('carddav')) {
            throw new Forbidden('CardDAV is turned off on this server.');
        }

        if ($root === 'files' && ! $this->enabled('files')) {
            throw new Forbidden('Files is turned off on this server.');
        }
    }

    /**
     * Another user's home answers 404 before sabre checks lock tokens: those
     * are stored by path, so a locked path there would otherwise answer 423
     * and show that it exists. A COPY or MOVE into another user's home fails
     * like one under a username that does not exist.
     */
    private function refuseOtherHomes(Server $server, RequestInterface $request): void
    {
        $own = $this->auth->username();

        if (! is_string($own) || $own === '') {
            return;
        }

        if (self::otherHome($request->getPath(), $own)) {
            throw new NotFound('Not found.');
        }

        $destination = $request->getHeader('Destination');

        if (! in_array(strtoupper($request->getMethod()), ['COPY', 'MOVE'], true) || ! is_string($destination) || $destination === '') {
            return;
        }

        try {
            $target = $server->calculateUri($destination);
        } catch (DavException) {
            return;
        }

        if (self::otherHome($target, $own)) {
            throw new Conflict('The destination node is not found');
        }
    }

    private static function otherHome(string $path, string $own): bool
    {
        $segments = explode('/', trim($path, '/'), 3);

        return in_array($segments[0], ['principals', 'calendars', 'addressbooks', 'files'], true)
            && ($segments[1] ?? '') !== ''
            && $segments[1] !== $own;
    }

    /**
     * Maximum upload size for a PUT to a file home: 413 at once when
     * Content-Length is over it, and a byte count while the body streams for
     * an upload without it. The announced length is checked too, so an
     * upload cut short leaves nothing. COPY is not an upload and has neither.
     */
    private function limitUpload(FileStorage $files, RequestInterface $request): void
    {
        $files->expectUpload(null, null);

        if (strtoupper($request->getMethod()) !== 'PUT' || ! self::underFiles($request->getPath())) {
            return;
        }

        $limit = max(1, (int) config('nexusdav.files.max_upload_mb', 128)) * 1048576;
        $header = $request->getHeader('Content-Length');
        $length = is_string($header) && ctype_digit($header) ? (int) $header : null;

        if ($length !== null && $length > $limit) {
            throw UploadTooLarge::forLimit($limit);
        }

        $files->expectUpload($limit, $length);
    }

    /**
     * A file is not a calendar object or a card, and the other way round:
     * COPY and MOVE stay on one side of /dav/files/.
     */
    private function refuseCopyAcrossFiles(Server $server, RequestInterface $request): void
    {
        $destination = $request->getHeader('Destination');

        if (! in_array(strtoupper($request->getMethod()), ['COPY', 'MOVE'], true) || ! is_string($destination) || $destination === '') {
            return;
        }

        try {
            $target = $server->calculateUri($destination);
        } catch (DavException) {
            return; // sabre answers a Destination outside /dav/ itself.
        }

        if (self::underFiles($request->getPath()) !== self::underFiles($target)) {
            throw new Forbidden('Files can only be copied or moved inside the file home.');
        }
    }

    public static function underFiles(string $path): bool
    {
        $path = trim($path, '/');

        return $path === 'files' || str_starts_with($path, 'files/');
    }

    private function requireReadIfPresent(Server $server, RequestInterface $request): void
    {
        $acl = $server->getPlugin('acl');

        if (! $acl instanceof AclPlugin) {
            return;
        }

        if (! $server->tree->nodeExists($request->getPath())) {
            return;
        }

        $acl->checkPrivileges($request->getPath(), '{DAV:}read');
    }

    /**
     * @return array{caldav: bool, carddav: bool, tasks: bool, notes: bool}
     */
    public function features(): array
    {
        return [
            'caldav' => $this->enabled('caldav'),
            'carddav' => $this->enabled('carddav'),
            'tasks' => $this->enabled('tasks'),
            'notes' => $this->enabled('notes'),
        ];
    }

    private function enabled(string $feature): bool
    {
        return filter_var(config('nexusdav.features.'.$feature, $feature !== 'files'), FILTER_VALIDATE_BOOLEAN);
    }
}
