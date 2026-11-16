<?php

namespace App\Http\Middleware;

use App\Services\Portal\PortalConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Portal switch. Off: every portal address, / included, redirects to
 * the admin panel, as / did before the portal. Persistent, so a Livewire
 * request from a portal page that was open is refused too.
 */
final class RequirePortal
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! PortalConfig::enabled()) {
            return redirect('/admin');
        }

        return $next($request);
    }
}
