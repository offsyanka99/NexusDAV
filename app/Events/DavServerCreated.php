<?php

namespace App\Events;

use Sabre\DAV\Server;

/**
 * A DAV server was built for the current request, with its plugins and event
 * handlers in place. Listeners can add sabre event handlers of their own.
 */
final class DavServerCreated
{
    public function __construct(public readonly Server $server) {}
}
