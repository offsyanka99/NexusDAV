<?php

namespace App\Services\Portal;

use App\Services\Dav\DavLimits;
use App\Services\Portal\Exceptions\InvalidItem;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Document;
use Sabre\VObject\Node;
use Sabre\VObject\ParseException;
use Sabre\VObject\Reader;

/**
 * The checks the CalDAV and CardDAV plugins make on a PUT, for the portal's
 * stores: parse (iCalendar or jCal, vCard or jCard), validate with the
 * CalDAV or CardDAV profile and repair what sabre repairs. A severe problem
 * is InvalidItem with sabre's message. On top of that: one kind of
 * component per calendar object, the calendar takes that component now
 * (switches and its component set), one UID, and the name limits.
 */
final class ItemValidator
{
    private const CALENDAR_COMPONENTS = ['VEVENT', 'VTODO', 'VJOURNAL'];

    public function calendar(string $data, CollectionRef $ref): ValidatedItem
    {
        $object = $this->parse($data, 'This resource only supports valid iCalendar 2.0 data. Parse error: ');
        $modified = str_starts_with($data, '[');

        if (! $object instanceof VCalendar) {
            $object->destroy();

            throw new InvalidItem('This collection can only support iCalendar objects.');
        }

        try {
            $types = [];

            foreach ($object->getComponents() as $component) {
                if (in_array($component->name, self::CALENDAR_COMPONENTS, true)) {
                    $types[$component->name] = true;
                }
            }

            if (count($types) !== 1) {
                throw new InvalidItem('A calendar object must hold exactly one kind of component: VEVENT, VTODO or VJOURNAL.');
            }

            $type = (string) array_key_first($types);

            if (! $ref->takes($type)) {
                throw new InvalidItem(match ($type) {
                    'VTODO' => 'This calendar does not take tasks.',
                    'VJOURNAL' => 'This calendar does not take notes.',
                    default => 'This calendar does not take events.',
                });
            }

            $modified = $this->validate($object, Node::PROFILE_CALDAV, 'Validation error in iCalendar: ') || $modified;

            $uids = [];

            foreach ($object->getComponents() as $component) {
                if ($component->name !== $type) {
                    continue;
                }

                $values = $component->select('UID');

                if (count($values) !== 1) {
                    throw new InvalidItem('Every component of a calendar object must have one UID.');
                }

                $uids[(string) reset($values)] = true;
            }

            if (count($uids) !== 1) {
                throw new InvalidItem('Every component of a calendar object must have the same UID.');
            }

            $uid = (string) array_key_first($uids);
            $this->assertUid($uid);

            return new ValidatedItem($modified ? $object->serialize() : $data, $uid, $type);
        } finally {
            $object->destroy();
        }
    }

    public function card(string $data): ValidatedItem
    {
        $object = $this->parse($data, 'This resource only supports valid vCard or jCard data. Parse error: ');
        $modified = str_starts_with($data, '[');

        if (! $object instanceof VCard) {
            $object->destroy();

            throw new InvalidItem('This collection can only support vcard objects.');
        }

        try {
            $modified = $this->validate($object, Node::PROFILE_CARDDAV, 'Validation error in vCard: ') || $modified;
            $values = $object->select('UID');

            if (count($values) !== 1) {
                throw new InvalidItem('A vCard must have one UID.');
            }

            $uid = (string) reset($values);
            $this->assertUid($uid);

            return new ValidatedItem($modified ? $object->serialize() : $data, $uid, 'VCARD');
        } finally {
            $object->destroy();
        }
    }

    /**
     * An object URI (the name of the resource in its collection).
     */
    public function assertUri(string $uri): void
    {
        if ($uri === '' || $uri === '.' || $uri === '..' || str_contains($uri, '/') || preg_match('/[\x00-\x1F\x7F]/', $uri) === 1) {
            throw new InvalidItem('The resource name is not valid.');
        }

        if (mb_strlen($uri) > DavLimits::NAME) {
            throw new InvalidItem('The resource name is longer than '.DavLimits::NAME.' characters.');
        }
    }

    private function parse(string $data, string $prefix): Document
    {
        try {
            return str_starts_with($data, '[') ? Reader::readJson($data) : Reader::read($data);
        } catch (ParseException $exception) {
            throw new InvalidItem($prefix.$exception->getMessage());
        }
    }

    /**
     * Returns whether sabre repaired or would re-serialize the object.
     */
    private function validate(Document $object, int $profile, string $prefix): bool
    {
        $modified = false;

        foreach ($object->validate($profile | Node::REPAIR) as $message) {
            if ($message['level'] >= 3) {
                throw new InvalidItem($prefix.$message['message']);
            }

            $modified = true;
        }

        return $modified;
    }

    private function assertUid(string $uid): void
    {
        if ($uid === '') {
            throw new InvalidItem('The UID is empty.');
        }

        if (mb_strlen($uid) > DavLimits::NAME) {
            throw new InvalidItem('The UID is longer than '.DavLimits::NAME.' characters.');
        }
    }
}
