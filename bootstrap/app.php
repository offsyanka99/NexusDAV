<?php

use App\Http\Controllers\DavController;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\RefuseWhileResetting;
use App\Http\Middleware\RequestLog;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustProxies;
use App\Http\Middleware\ValidatePostSize;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies as BaseTrustProxies;
use Illuminate\Http\Middleware\ValidatePostSize as BaseValidatePostSize;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$logLevel = $_ENV['LOG_LEVEL'] ?? $_SERVER['LOG_LEVEL'] ?? getenv('LOG_LEVEL');

// Compose injects LOG_LEVEL="" when it is unset. docker compose exec does not run the entrypoint unset.
if (is_string($logLevel) && trim($logLevel) === '') {
    putenv('LOG_LEVEL');
    unset($_ENV['LOG_LEVEL'], $_SERVER['LOG_LEVEL']);
}

$appKey = $_ENV['APP_KEY'] ?? $_SERVER['APP_KEY'] ?? getenv('APP_KEY');

// The container keeps the key on the data volume so restarts do not depend on .env.
if ((! is_string($appKey) || $appKey === '') && is_file('/data/app.key') && is_readable('/data/app.key')) {
    $storedKey = trim((string) file_get_contents('/data/app.key'));

    if ($storedKey !== '') {
        putenv('APP_KEY='.$storedKey);
        $_ENV['APP_KEY'] = $storedKey;
        $_SERVER['APP_KEY'] = $storedKey;
    }
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::get('/health', HealthController::class);

            // Route::any only matches the standard verbs, so PROPFIND and the rest would never hit the controller.
            $davMethods = ['OPTIONS', 'GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'PROPFIND', 'PROPPATCH', 'REPORT', 'MKCOL', 'MKCALENDAR', 'COPY', 'MOVE', 'LOCK', 'UNLOCK'];
            Route::match($davMethods, '/dav/{path?}', DavController::class)->where('path', '.*');

            // Built from APP_URL, so a forged X-Forwarded-Host cannot choose the target.
            $redirectToDav = function (): RedirectResponse {
                return redirect()->away(rtrim((string) config('app.url'), '/').'/dav/', 301);
            };

            Route::match(['GET', 'HEAD', 'PROPFIND', 'OPTIONS'], '/.well-known/caldav', $redirectToDav);
            Route::match(['GET', 'HEAD', 'PROPFIND', 'OPTIONS'], '/.well-known/carddav', $redirectToDav);
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // First, so every answer is logged, including those from the middleware after it.
        $middleware->prepend(RequestLog::class);
        // During a factory reset, before sessions, authentication and DAV.
        $middleware->append(RefuseWhileResetting::class);
        // The proxy list comes from config('nexusdav.trusted_proxies') at request time.
        $middleware->replace(BaseTrustProxies::class, TrustProxies::class);
        // An upload to a file home has its own limit instead of post_max_size.
        $middleware->replace(BaseValidatePostSize::class, ValidatePostSize::class);
        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX,
        );
        $middleware->web(append: SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
