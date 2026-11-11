<?php

namespace App\Http\Middleware;

use App\Services\ResetFlag;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While a factory reset runs, every request but the health checks answers
 * 503, before authentication, so no client or admin writes into a server
 * that is half reset. The request that runs the reset passed this before it
 * set the flag.
 */
final class RefuseWhileResetting
{
    private const ALWAYS = ['/health', '/up'];

    public function __construct(private ResetFlag $flag) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->getPathInfo(), self::ALWAYS, true) || ! $this->flag->active()) {
            return $next($request);
        }

        return response('The server is being reset. Try again in a minute.', 503, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Retry-After' => (string) ResetFlag::RETRY_AFTER_SECONDS,
        ]);
    }
}
