<?php

namespace App\Services\Dav\Push;

use Sabre\Xml\Writer;
use Sabre\Xml\XmlSerializable;

/**
 * The `supported-triggers` property: the deepest content update, and the
 * property update when the resource has one.
 */
final class SupportedTriggers implements XmlSerializable
{
    public function __construct(private string $contentDepth, private ?string $propertyDepth) {}

    public function xmlSerialize(Writer $writer): void
    {
        $writer->startElement('{'.PushPlugin::NS.'}content-update');
        $writer->writeElement('{DAV:}depth', $this->contentDepth);
        $writer->endElement();

        if ($this->propertyDepth !== null) {
            $writer->startElement('{'.PushPlugin::NS.'}property-update');
            $writer->writeElement('{DAV:}depth', $this->propertyDepth);
            $writer->endElement();
        }
    }
}
