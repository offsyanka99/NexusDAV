<?php

namespace App\Services\Dav;

/**
 * PostgreSQL returns bytea as a stream, or sometimes as a \x hex literal.
 * sabre stores the original calendar and card bytes and expects a string back.
 */
final class BinaryValue
{
    public static function toString(mixed $value): string
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            return is_string($contents) ? self::decodeHexLiteral($contents) : '';
        }

        if (! is_string($value)) {
            return '';
        }

        return self::decodeHexLiteral($value);
    }

    private static function decodeHexLiteral(string $value): string
    {
        if (preg_match('/^\\\\x[0-9a-fA-F]*$/', $value) !== 1) {
            return $value;
        }

        $decoded = hex2bin(substr($value, 2));

        return is_string($decoded) ? $decoded : $value;
    }
}
