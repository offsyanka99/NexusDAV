<?php

namespace App\Services\Push;

use Psr\Http\Client\ClientInterface;

/**
 * Makes the HTTP client for one checked push service. Tests replace it
 * with a fake push service.
 */
class PushClientFactory
{
    public function make(PushEndpoint $endpoint): ClientInterface
    {
        return new PushHttpClient($endpoint);
    }
}
