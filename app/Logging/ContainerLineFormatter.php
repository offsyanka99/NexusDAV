<?php

namespace App\Logging;

use DateTimeZone;
use Monolog\Formatter\LineFormatter;
use Monolog\LogRecord;
use Throwable;

/**
 * The container log line: "2026-11-04 14:04:44 ERROR message {context}".
 *
 * Log viewers such as Dozzle read the level from a word at the start of the
 * line once the timestamp is stripped. Laravel's default
 * "[date] production.ERROR:" glues the level to the environment name, so they
 * cannot see it and every line looks the same. Stack traces stay on the lines
 * that follow the error.
 *
 * The time is in the server time zone (Configuration, NEXUSDAV_TIMEZONE), so
 * the app and the worker log in the same zone as the panel. The application
 * itself keeps running in UTC.
 */
final class ContainerLineFormatter extends LineFormatter
{
    public const FORMAT = "%datetime% %level_name% %message% %context% %extra%\n";

    public function __construct()
    {
        parent::__construct(self::FORMAT, 'Y-m-d H:i:s', true, true, true);
    }

    public function format(LogRecord $record): string
    {
        return parent::format($record->with(datetime: $record->datetime->setTimezone(self::timezone())));
    }

    private static function timezone(): DateTimeZone
    {
        try {
            $zone = function_exists('config') ? config('nexusdav.default_timezone', 'UTC') : 'UTC';

            return new DateTimeZone(is_string($zone) && $zone !== '' ? $zone : 'UTC');
        } catch (Throwable) {
            // No container yet, or a zone that does not exist.
            return new DateTimeZone('UTC');
        }
    }
}
