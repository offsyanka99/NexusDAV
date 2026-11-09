<?php

namespace App\Services\Push;

/**
 * Resolves a push service's host name to its addresses.
 */
interface PushResolver
{
    /**
     * @return list<string> IPv4 and IPv6 addresses, empty when the name does not resolve
     */
    public function addresses(string $host): array;
}
