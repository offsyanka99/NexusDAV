<?php

namespace App\Services\Dav;

use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\PropPatch;

/**
 * Length and range checks for values a DAV client sends, so PostgreSQL never
 * sees a value its column refuses (that was a 500) and SQLite does not keep
 * what PostgreSQL would refuse. The limits match the column sizes.
 */
final class DavLimits
{
    public const NAME = 200;

    public const DISPLAY_NAME = 255;

    public const COLOR = 10;

    public const REFRESH_RATE = 32;

    public const PROPERTY_NAME = 255;

    private const INT_MIN = -2147483648;

    private const INT_MAX = 2147483647;

    /**
     * @var array<string, int|string>
     */
    public const CALENDAR = [
        '{DAV:}displayname' => self::DISPLAY_NAME,
        '{http://apple.com/ns/ical/}calendar-color' => self::COLOR,
        '{http://apple.com/ns/ical/}calendar-order' => 'int',
    ];

    /**
     * @var array<string, int|string>
     */
    public const SUBSCRIPTION = [
        '{DAV:}displayname' => self::DISPLAY_NAME,
        '{http://apple.com/ns/ical/}calendar-color' => self::COLOR,
        '{http://apple.com/ns/ical/}calendar-order' => 'int',
        '{http://apple.com/ns/ical/}refreshrate' => self::REFRESH_RATE,
    ];

    /**
     * @var array<string, int|string>
     */
    public const ADDRESS_BOOK = [
        '{DAV:}displayname' => self::DISPLAY_NAME,
    ];

    /**
     * A collection, resource, or UID name. Thrown before anything is written.
     */
    public static function assertName(mixed $name, string $what): void
    {
        if (is_string($name) && mb_strlen($name) > self::NAME) {
            throw new BadRequest($what.' is longer than '.self::NAME.' characters.');
        }
    }

    /**
     * Properties sent with MKCALENDAR or extended MKCOL.
     *
     * @param  array<string, mixed>  $properties
     * @param  array<string, int|string>  $limits
     */
    public static function assertProperties(array $properties, array $limits): void
    {
        foreach ($limits as $name => $limit) {
            if (array_key_exists($name, $properties) && ! self::fits($properties[$name], $limit)) {
                throw new BadRequest($name.' is too long or out of range.');
            }
        }
    }

    /**
     * PROPPATCH: an oversized value is refused with 403 before any handler
     * runs, and sabre then fails the other properties with 424.
     *
     * @param  array<string, int|string>  $limits
     */
    public static function refuse(PropPatch $propPatch, array $limits): void
    {
        foreach ($propPatch->getMutations() as $name => $value) {
            if (isset($limits[$name]) && ! self::fits($value, $limits[$name])) {
                $propPatch->setResultCode($name, 403);
            }
        }
    }

    private static function fits(mixed $value, int|string $limit): bool
    {
        if ($value === null) {
            return true;
        }

        if ($limit === 'int') {
            if (is_int($value)) {
                return $value >= self::INT_MIN && $value <= self::INT_MAX;
            }

            if (! is_string($value) || preg_match('/^\s*-?\d{1,10}\s*$/', $value) !== 1) {
                return false;
            }

            $number = (int) $value;

            return $number >= self::INT_MIN && $number <= self::INT_MAX;
        }

        return ! is_string($value) || mb_strlen($value) <= $limit;
    }
}
