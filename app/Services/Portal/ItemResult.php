<?php

namespace App\Services\Portal;

/**
 * What a store wrote: the object's URI in its collection, its new ETag (the
 * quoted form, as DAV sends it) and its UID.
 */
final class ItemResult
{
    public function __construct(
        public readonly string $uri,
        public readonly string $etag,
        public readonly string $uid,
    ) {}
}
