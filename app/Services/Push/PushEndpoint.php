<?php

namespace App\Services\Push;

/**
 * A push resource that passed the SSRF guard: the connection goes to this
 * address and nowhere else.
 */
final class PushEndpoint
{
    public function __construct(
        public readonly string $scheme,
        public readonly string $host,
        public readonly int $port,
        public readonly string $address,
    ) {}

    /**
     * The form curl's CURLOPT_RESOLVE expects: IPv6 in brackets.
     */
    public function resolveEntry(): string
    {
        $address = str_contains($this->address, ':') ? '['.$this->address.']' : $this->address;

        return $this->host.':'.$this->port.':'.$address;
    }
}
