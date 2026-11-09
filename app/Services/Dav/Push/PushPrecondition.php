<?php

namespace App\Services\Dav\Push;

use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Server;

/**
 * A 403 with one of the draft's preconditions in the DAV:error body:
 * invalid-subscription, push-not-available or no-trigger-supported.
 */
final class PushPrecondition extends Forbidden
{
    public const INVALID_SUBSCRIPTION = 'invalid-subscription';

    public const NOT_AVAILABLE = 'push-not-available';

    public const NO_TRIGGER = 'no-trigger-supported';

    public function __construct(public readonly string $condition, string $message)
    {
        parent::__construct($message);
    }

    public function serialize(Server $server, \DOMElement $errorNode)
    {
        $document = $errorNode->ownerDocument;

        if ($document instanceof \DOMDocument) {
            $errorNode->appendChild($document->createElementNS(PushPlugin::NS, 'P:'.$this->condition));
        }
    }
}
