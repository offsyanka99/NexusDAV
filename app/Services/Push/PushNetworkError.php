<?php

namespace App\Services\Push;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * The push service could not be reached: a connection error or a timeout.
 * The message is curl's description of the error, never the URL.
 */
final class PushNetworkError extends RuntimeException implements NetworkExceptionInterface
{
    public function __construct(private RequestInterface $request, string $message)
    {
        parent::__construct($message);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
