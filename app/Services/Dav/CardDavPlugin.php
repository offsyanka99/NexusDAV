<?php

namespace App\Services\Dav;

use Sabre\CardDAV\Plugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * sabre converts the body of every vCard GET, and a HEAD runs the same
 * hook with an empty body, which the parser rejects with a 500.
 */
final class CardDavPlugin extends Plugin
{
    public function httpAfterGet(RequestInterface $request, ResponseInterface $response)
    {
        // sabre runs a HEAD as a GET on a copy of the request.
        if ($this->server->httpRequest->getMethod() === 'HEAD') {
            return;
        }

        parent::httpAfterGet($request, $response);
    }
}
