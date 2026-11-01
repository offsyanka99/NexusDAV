<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as BaseTrustProxies;

/**
 * Reads the trusted proxies from config at request time, so the value
 * survives a cached config. bootstrap/app.php runs before config exists.
 */
class TrustProxies extends BaseTrustProxies
{
    /**
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        $value = config('nexusdav.trusted_proxies');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
