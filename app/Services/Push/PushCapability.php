<?php

namespace App\Services\Push;

/**
 * What a push-capable resource offers (docs/design/webdav-push.md): its
 * topic, the deepest content and property updates it supports (null for
 * none), and the collection whose sync token goes into its messages.
 */
final class PushCapability
{
    public function __construct(
        public readonly string $path,
        public readonly string $topic,
        public readonly string $contentDepth,
        public readonly ?string $propertyDepth,
        public readonly ?string $source = null,
    ) {}
}
