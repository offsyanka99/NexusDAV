<?php

namespace App\Services\Dav\Push;

use Sabre\Xml\Writer;
use Sabre\Xml\XmlSerializable;

/**
 * The `transports` property: Web Push with the server's VAPID public key,
 * which DAVx5 needs before it registers.
 */
final class Transports implements XmlSerializable
{
    public function __construct(private string $vapidPublicKey) {}

    public function xmlSerialize(Writer $writer): void
    {
        $writer->startElement('{'.PushPlugin::NS.'}web-push');
        $writer->startElement('{'.PushPlugin::NS.'}vapid-public-key');
        $writer->writeAttribute('type', 'p256ecdsa');
        $writer->text($this->vapidPublicKey);
        $writer->endElement();
        $writer->endElement();
    }
}
