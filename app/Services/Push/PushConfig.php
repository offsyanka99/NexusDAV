<?php

namespace App\Services\Push;

use App\Services\SettingsService;
use Illuminate\Support\Facades\Log;

/**
 * The WebDAV-Push settings as read at request time, and the push log lines,
 * which have their own lowest level (NEXUSDAV_PUSH_LOG_LEVEL).
 */
final class PushConfig
{
    public static function enabled(): bool
    {
        return filter_var(config('nexusdav.features.push', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * File folders offer push only with Files and Include Files in push on.
     */
    public static function files(): bool
    {
        return self::enabled()
            && filter_var(config('nexusdav.features.files', false), FILTER_VALIDATE_BOOLEAN)
            && filter_var(config('nexusdav.push.files', false), FILTER_VALIDATE_BOOLEAN);
    }

    public static function maxSubscriptions(): int
    {
        return max(1, (int) config('nexusdav.push.max_subscriptions', 100));
    }

    public static function allowPrivate(): bool
    {
        return filter_var(config('nexusdav.push.allow_private', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        $levels = SettingsService::LOG_LEVELS;
        $lowest = array_search((string) config('nexusdav.push.log_level', 'info'), $levels, true);
        $wanted = array_search($level, $levels, true);

        if ($wanted === false || ($lowest !== false && $wanted < $lowest)) {
            return;
        }

        Log::log($level, $message, $context);
    }
}
