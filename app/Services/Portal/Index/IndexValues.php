<?php

namespace App\Services\Portal\Index;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Sabre\VObject\Component;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\Property;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;
use Throwable;

/**
 * The shared conversions of the indexers: dates (UTC, or a wall-clock value
 * stored as UTC and flagged), text clipped to its column, and the
 * lower-cased search text.
 */
final class IndexValues
{
    public const SEARCH_LIMIT = 4000;

    /**
     * A DATE or DATE-TIME property: the stored value and whether it is an
     * all-day date or a floating time.
     *
     * @return array{at: ?DateTimeImmutable, all_day: bool, floating: bool}
     */
    public static function moment(mixed $property): array
    {
        $none = ['at' => null, 'all_day' => false, 'floating' => false];

        if (! $property instanceof DateTimeProperty) {
            return $none;
        }

        try {
            $utc = new DateTimeZone('UTC');

            if (! $property->hasTime()) {
                return ['at' => DateTimeImmutable::createFromInterface($property->getDateTime($utc))->setTime(0, 0), 'all_day' => true, 'floating' => false];
            }

            if ($property->isFloating()) {
                return ['at' => DateTimeImmutable::createFromInterface($property->getDateTime($utc)), 'all_day' => false, 'floating' => true];
            }

            return ['at' => DateTimeImmutable::createFromInterface($property->getDateTime())->setTimezone($utc), 'all_day' => false, 'floating' => false];
        } catch (Throwable) {
            return $none;
        }
    }

    /**
     * The first property of that name on the component, or null.
     */
    public static function first(Component $component, string $name): ?Property
    {
        foreach ($component->select($name) as $property) {
            if ($property instanceof Property) {
                return $property;
            }
        }

        return null;
    }

    public static function has(Component $component, string $name): bool
    {
        return self::first($component, $name) !== null;
    }

    public static function string(Component $component, string $name): string
    {
        return (string) self::first($component, $name);
    }

    public static function plusDuration(DateTimeImmutable $start, mixed $duration): ?DateTimeImmutable
    {
        if (! $duration instanceof Property) {
            return null;
        }

        try {
            $interval = DateTimeParser::parseDuration((string) $duration);

            return $interval instanceof DateInterval ? $start->add($interval) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Null for a year PostgreSQL's timestamp cannot hold (0, or past 9999),
     * which a DATE value in a client's object can still have.
     */
    public static function format(?DateTimeInterface $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $year = (int) $value->format('Y');

        return $year >= 1 && $year <= 9999 ? $value->format('Y-m-d H:i:s') : null;
    }

    /**
     * Without NUL bytes, which PostgreSQL's text columns refuse.
     */
    public static function clean(string $value): string
    {
        return str_replace("\0", '', $value);
    }

    public static function text(mixed $property, int $limit = 255): ?string
    {
        if ($property === null) {
            return null;
        }

        $value = trim(self::clean((string) $property));

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    /**
     * Every value of the named properties of the component.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function values(Component $component, array $names): array
    {
        $values = [];

        foreach ($names as $name) {
            foreach ($component->select($name) as $property) {
                $value = trim($property instanceof Property ? implode(' ', array_map('strval', (array) $property->getParts())) : (string) $property);

                if ($value !== '') {
                    $values[] = $value;
                }
            }
        }

        return $values;
    }

    /**
     * @param  list<string|null>  $parts
     */
    public static function search(array $parts): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', self::clean(implode(' ', array_filter($parts, static fn (?string $part): bool => $part !== null && $part !== '')))));

        return $text === '' ? null : mb_substr(mb_strtolower($text), 0, self::SEARCH_LIMIT);
    }

    public static function upper(mixed $property, int $limit = 32): ?string
    {
        $value = self::text($property, $limit);

        return $value === null ? null : strtoupper($value);
    }
}
