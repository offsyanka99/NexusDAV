<?php

namespace App\Portal;

use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Dates and times as the portal shows them: in the signed-in user's time
 * zone (else the server's), with the server-wide time format and week start
 * (Configuration), month and day names in the user's language, and ISO
 * week numbers.
 */
final class PortalTime
{
    public static function timezone(?User $user = null): string
    {
        $user ??= auth()->user();

        if ($user instanceof User) {
            return $user->resolvedTimezone();
        }

        return (string) config('nexusdav.default_timezone', 'UTC');
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    /**
     * The moment in the user's time zone.
     */
    public static function local(DateTimeInterface $value): CarbonImmutable
    {
        return CarbonImmutable::instance($value)->setTimezone(self::timezone());
    }

    public static function uses12Hour(): bool
    {
        return (string) config('nexusdav.time_format', '24') === '12';
    }

    /**
     * 0 (Sunday) to 6 (Saturday).
     */
    public static function weekStart(): int
    {
        $day = (int) config('nexusdav.week_start', 1);

        return $day >= 0 && $day <= 6 ? $day : 1;
    }

    /**
     * The short date of the user's language, such as "Jan 1, 2027" or
     * "1 янв. 2027 г.".
     */
    public static function date(DateTimeInterface $value): string
    {
        return self::local($value)->isoFormat('ll');
    }

    public static function time(DateTimeInterface $value): string
    {
        return self::local($value)->format(self::uses12Hour() ? 'g:i A' : 'H:i');
    }

    public static function dateTime(DateTimeInterface $value): string
    {
        return self::date($value).' '.self::time($value);
    }

    /**
     * The first day of the week that holds the day, at midnight, with the
     * server's week start.
     */
    public static function startOfWeek(DateTimeInterface $day): CarbonImmutable
    {
        $local = self::local($day)->startOfDay();
        $back = ($local->dayOfWeek - self::weekStart() + 7) % 7;

        return $local->subDays($back);
    }

    /**
     * The ISO 8601 week number of the day (weeks start on Monday, week 1
     * holds the year's first Thursday), whatever the week start setting.
     */
    public static function weekNumber(DateTimeInterface $day): int
    {
        return (int) self::local($day)->format('W');
    }

    public static function showWeekNumbers(?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user instanceof User && $user->show_week_numbers;
    }
}
