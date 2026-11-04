<?php

namespace App\Services\Dav;

/**
 * Sync tokens for calendars and address books: "<collection id>-<change number>",
 * sent after sabre's http://sabre.io/ns/sync/ prefix. The id ties a token to the
 * collection that issued it. Ids are never reused (autoincrement on SQLite, a
 * sequence on PostgreSQL), so a collection that is deleted and created again
 * with the same URI does not accept the old collection's tokens, and its ctag
 * differs even when the change numbers match.
 *
 * A token the collection cannot use resolves to null, which sabre answers with
 * 403 and DAV:valid-sync-token; the client then syncs from scratch. That
 * includes a token older than synctoken_min, the oldest change number the
 * collection still has after history pruning.
 */
final class SyncToken
{
    /**
     * Change numbers start at 1. sabre would read 0 as "no token" and send
     * every item without deletions, so 0 is not accepted.
     */
    private const NUMBER = '[1-9][0-9]{0,9}';

    public static function format(int $collectionId, int $number): string
    {
        return $collectionId.'-'.$number;
    }

    /**
     * The change number to read changes from, or null when the token belongs
     * to another collection, is ahead of the collection's current number, is
     * older than the oldest change number still kept, or is malformed. A plain
     * number is a token issued before the id was added (implementation plan
     * v1.x, Decision 20): it is accepted while it is not ahead and not older
     * than the kept history, and the reply carries a token in the new form.
     */
    public static function resolve(mixed $token, int $collectionId, int $current, int $oldest = 1): ?int
    {
        if (! is_string($token)) {
            return null;
        }

        if (preg_match('/^('.self::NUMBER.')-('.self::NUMBER.')$/', $token, $matches) === 1) {
            if ((int) $matches[1] !== $collectionId) {
                return null;
            }

            $number = (int) $matches[2];
        } elseif (preg_match('/^'.self::NUMBER.'$/', $token) === 1) {
            $number = (int) $token;
        } else {
            return null;
        }

        if ($number > $current || $number < $oldest) {
            return null;
        }

        return $number;
    }

    /**
     * sabre passes null for a first sync; an empty element arrives as "".
     */
    public static function isInitial(mixed $token): bool
    {
        return $token === null || $token === '';
    }
}
