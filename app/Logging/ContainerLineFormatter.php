<?php

namespace App\Logging;

use Monolog\Formatter\LineFormatter;

/**
 * The container log line: "2026-11-04 14:04:44 ERROR message {context}".
 *
 * Log viewers such as Dozzle read the level from a word at the start of the
 * line once the timestamp is stripped. Laravel's default
 * "[date] production.ERROR:" glues the level to the environment name, so they
 * cannot see it and every line looks the same. Stack traces stay on the lines
 * that follow the error.
 */
final class ContainerLineFormatter extends LineFormatter
{
    public const FORMAT = "%datetime% %level_name% %message% %context% %extra%\n";

    public function __construct()
    {
        parent::__construct(self::FORMAT, 'Y-m-d H:i:s', true, true, true);
    }
}
