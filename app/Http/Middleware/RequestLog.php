<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AuthThrottle;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Gives every request an ID and writes one log line when it is answered:
 * method, path, status, user, client address and duration. The ID is added
 * to every other log line of the request, sent back as X-Request-Id, and kept
 * on Diagnostics rows. The query string, headers and body are never logged.
 */
final class RequestLog
{
    public const CONTEXT_KEY = 'request_id';

    public const HEADER = 'X-Request-Id';

    /**
     * DavController sets this to the signed-in DAV username.
     */
    public const USER_ATTRIBUTE = 'nexusdav.user';

    /**
     * Healthchecks run every 30 seconds. They get a line only when they fail.
     */
    private const QUIET_PATHS = ['/health', '/up'];

    public function __construct(private AuthThrottle $throttle) {}

    public static function id(): ?string
    {
        $id = Context::get(self::CONTEXT_KEY);

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);
        $id = bin2hex(random_bytes(8));
        Context::add(self::CONTEXT_KEY, $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        try {
            $this->write($request, $response->getStatusCode(), $started);
        } catch (Throwable) {
            // Logging must never change the answer, and there is nowhere left to report this.
        }

        return $response;
    }

    private function write(Request $request, int $status, int $started): void
    {
        $path = substr($request->getPathInfo(), 0, 512);

        if ($status < 400 && in_array($path, self::QUIET_PATHS, true)) {
            return;
        }

        $context = [
            'method' => substr((string) preg_replace('/[^A-Z0-9_-]/', '', $request->getRealMethod()), 0, 16),
            'path' => $path,
            'status' => $status,
            'user' => $this->user($request),
            'ip' => $this->throttle->clientIp(),
            'duration_ms' => round((hrtime(true) - $started) / 1_000_000, 1),
        ];

        // 401 is the normal challenge before a client sends its password;
        // a rejected password has its own warning line.
        match (true) {
            $status >= 500 => Log::error('Request', $context),
            $status >= 400 && $status !== 401 => Log::warning('Request', $context),
            default => Log::info('Request', $context),
        };
    }

    private function user(Request $request): ?string
    {
        $dav = $request->attributes->get(self::USER_ATTRIBUTE);

        if (is_string($dav) && $dav !== '') {
            return $dav;
        }

        // hasUser() never reads the session or the database; user() could.
        $guard = Auth::guard();

        if ($guard->hasUser()) {
            $user = $guard->user();

            return $user instanceof User ? $user->username : null;
        }

        return null;
    }
}
