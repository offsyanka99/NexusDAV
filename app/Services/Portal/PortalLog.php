<?php

namespace App\Services\Portal;

use App\Services\SettingsService;
use Illuminate\Support\Facades\Log;

/**
 * Portal log lines (sign-in, changes, imports), which have their own lowest
 * level (NEXUSDAV_PORTAL_LOG_LEVEL), like the push lines. A line names the
 * user and what happened, never a field value, a file name or item content.
 */
final class PortalLog
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        $levels = SettingsService::LOG_LEVELS;
        $lowest = array_search((string) config('nexusdav.portal.log_level', 'info'), $levels, true);
        $wanted = array_search($level, $levels, true);

        if ($wanted === false || ($lowest !== false && $wanted < $lowest)) {
            return;
        }

        Log::log($level, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }
}
