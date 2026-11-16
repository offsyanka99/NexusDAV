<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\LocaleCatalog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin panel and user portal: the signed-in user's language, else the
 * server default, else English. The sign-in page has no user, so it gets the
 * server default. The previous locale is restored so nothing outside the
 * panel request (DAV, /health, logs) sees the user's language.
 */
final class SetPanelLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $previous = App::getLocale();

        App::setLocale($user instanceof User ? $user->resolvedLocale() : app(LocaleCatalog::class)->default());

        try {
            return $next($request);
        } finally {
            App::setLocale($previous);
        }
    }
}
