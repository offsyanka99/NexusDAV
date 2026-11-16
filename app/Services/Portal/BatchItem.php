<?php

namespace App\Services\Portal;

/**
 * One object of a batch: its text, the URI to store it at (null: one is
 * made from the UID) and, to refuse it when another device changed the
 * stored item, the ETag the caller read.
 */
final class BatchItem
{
    public function __construct(
        public readonly string $data,
        public readonly ?string $uri = null,
        public readonly ?string $etag = null,
    ) {}
}
