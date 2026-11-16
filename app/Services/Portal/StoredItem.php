<?php

namespace App\Services\Portal;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Reader;

/**
 * One stored calendar object or vCard as a reader returns it: the
 * collection, the URI, the current ETag (quoted, as DAV sends it; a form
 * keeps it for the conflict check) and the text as stored.
 */
final class StoredItem
{
    public function __construct(
        public readonly CollectionRef $collection,
        public readonly string $uri,
        public readonly string $etag,
        public readonly string $data,
    ) {}

    /**
     * The object parsed fresh on each call, so an edit cannot touch another
     * caller's copy.
     */
    public function calendar(): VCalendar
    {
        $object = Reader::read($this->data);

        if (! $object instanceof VCalendar) {
            throw new \UnexpectedValueException('The stored object is not an iCalendar object.');
        }

        return $object;
    }

    public function card(): VCard
    {
        $object = Reader::read($this->data);

        if (! $object instanceof VCard) {
            throw new \UnexpectedValueException('The stored object is not a vCard.');
        }

        return $object;
    }
}
