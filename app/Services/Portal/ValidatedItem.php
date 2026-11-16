<?php

namespace App\Services\Portal;

/**
 * An iCalendar object or a vCard that passed the CalDAV or CardDAV rules:
 * the text to store (repaired when sabre repaired it), its UID, and its
 * component (VEVENT, VTODO, VJOURNAL or VCARD).
 */
final class ValidatedItem
{
    public function __construct(
        public readonly string $data,
        public readonly string $uid,
        public readonly string $component,
    ) {}
}
