<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Portal\PortalLog;
use App\Support\PanelSessions;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Each panel's own idle lifetime in the shared session. A request to a panel
 * whose lifetime has passed since that panel's last request signs the user
 * out of both panels and opens that panel's sign-in page. Persistent, so a
 * Livewire action counts and is refused the same way: it gets a 401, which
 * the session timeout script turns into the sign-in page.
 */
final class PanelIdleTimeout
{
    public function handle(Request $request, Closure $next, string $panel): Response
    {
        $guard = Filament::getPanel($panel)->auth();

        if (! $guard->check()) {
            return $next($request);
        }

        $key = PanelSessions::activityKey($panel);
        $last = session()->get($key);
        $now = now()->getTimestamp();

        if (is_int($last) && $now - $last >= PanelSessions::lifetime($panel) * 60) {
            $user = $guard->user();

            $guard->logout();
            session()->invalidate();
            session()->regenerateToken();

            if ($panel === 'portal') {
                PortalLog::info('Portal session ended after inactivity', [
                    'username' => $user instanceof User ? $user->username : null,
                ]);
            }

            if ($request->hasHeader('X-Livewire')) {
                abort(401);
            }

            return redirect()->to((string) Filament::getPanel($panel)->getLoginUrl(['timeout' => 1]));
        }

        session()->put($key, $now);

        return $next($request);
    }
}
