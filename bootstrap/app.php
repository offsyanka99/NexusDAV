<?php

use App\Http\Controllers\HealthController;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $trustedProxies = env('TRUSTED_PROXIES', '*');

        if (! is_string($trustedProxies) || trim($trustedProxies) === '') {
            $trustedProxies = '*';
        }

        $trustedProxies = trim($trustedProxies);

        if ($trustedProxies !== '*') {
            $trustedProxies = array_values(array_filter(array_map(
                trim(...),
                explode(',', $trustedProxies),
            )));
        }

        $middleware->trustProxies(
            at: $trustedProxies === [] ? '*' : $trustedProxies,
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
