<?php

namespace App\Http\Middleware;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The admin panel's authentication. A signed-in user who may not use the
 * panel (not an admin, or no longer one) gets an Access denied page with
 * status 403 instead of Filament's bare 403, and stays signed in. Signing
 * out still works from there. Persistent, so a Livewire request from an
 * admin page that was open gets the same 403.
 */
class AuthenticateAdmin extends Authenticate
{
    /**
     * @param  array<string>  $guards
     */
    protected function authenticate($request, array $guards): void
    {
        $user = Filament::auth()->user();
        $panel = Filament::getCurrentOrDefaultPanel();

        if ($user instanceof User && ! $user->canAccessPanel($panel)) {
            if ($request->routeIs('filament.'.$panel->getId().'.auth.logout')) {
                $this->auth->shouldUse(Filament::getAuthGuard());

                return;
            }

            $portal = Filament::getPanel('portal');

            throw new HttpResponseException(response()->view('filament.access-denied', [
                'portalUrl' => $user->canAccessPanel($portal) ? $portal->getUrl() : null,
                'logoutUrl' => $panel->getLogoutUrl(),
            ], 403));
        }

        parent::authenticate($request, $guards);
    }
}
