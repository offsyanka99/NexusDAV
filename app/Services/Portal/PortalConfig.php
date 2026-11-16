<?php

namespace App\Services\Portal;

/**
 * The user portal settings as read at request time.
 */
final class PortalConfig
{
    /**
     * The Portal switch (Settings, User portal). Off: every portal address
     * redirects to /admin and nobody can sign in to the portal.
     */
    public static function enabled(): bool
    {
        return filter_var(config('nexusdav.portal.enabled', false), FILTER_VALIDATE_BOOLEAN);
    }
}
