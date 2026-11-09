<?php

namespace App\Services\Push;

use DateTimeImmutable;

/**
 * A parsed push-register request. A trigger that was not asked for is
 * false; a depth the client left out is null.
 */
final class PushRegistration
{
    /**
     * @param  list<string>|null  $properties
     */
    public function __construct(
        public readonly string $pushResource,
        public readonly string $publicKey,
        public readonly string $authSecret,
        public readonly bool $contentUpdate,
        public readonly ?string $contentDepth,
        public readonly bool $propertyUpdate,
        public readonly ?string $propertyDepth,
        public readonly ?array $properties,
        public readonly ?DateTimeImmutable $expires,
    ) {}
}
