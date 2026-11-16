<?php

namespace App\Support;

use App\Services\Portal\PortalConfig;

/**
 * Both panels share one session (one sign-in). Each panel has its own idle
 * lifetime and keeps its own last-activity time in that session
 * (PanelIdleTimeout). The session itself, its database row and its cookie,
 * lives for the longer of the two lifetimes, so neither panel cuts the
 * other's session short. With the portal off, only the admin lifetime counts.
 */
final class PanelSessions
{
    public const PANELS = ['admin', 'portal'];

    /**
     * Minutes without activity in the panel before the user is signed out.
     */
    public static function lifetime(string $panel): int
    {
        $minutes = $panel === 'portal'
            ? config('nexusdav.portal.session_lifetime', 480)
            : config('nexusdav.admin.session_lifetime', 120);

        return max(1, (int) $minutes);
    }

    /**
     * The lifetime of the shared session: session.lifetime.
     */
    public static function sessionLifetime(): int
    {
        if (! PortalConfig::enabled()) {
            return self::lifetime('admin');
        }

        return max(self::lifetime('admin'), self::lifetime('portal'));
    }

    /**
     * The session key that holds the panel's last activity (a Unix time).
     */
    public static function activityKey(string $panel): string
    {
        return $panel.'_last_activity';
    }

    /**
     * A new sign-in starts every panel's idle time again: the panel signed
     * in to counts from now, the others from their first request.
     */
    public static function signedIn(string $panel): void
    {
        foreach (self::PANELS as $other) {
            session()->forget(self::activityKey($other));
        }

        session()->put(self::activityKey($panel), now()->getTimestamp());
    }
}
