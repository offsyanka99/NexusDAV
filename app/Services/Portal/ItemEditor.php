<?php

namespace App\Services\Portal;

use DateTimeImmutable;
use InvalidArgumentException;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Document;

/**
 * Changes the properties a portal form edits on a parsed item and leaves
 * every other property as stored: alarms, attendees, photos, custom fields,
 * parameters (Decision 13). Each app's field mapper (PR 53, 56, 57, 59)
 * calls these generic operations; the editor knows no field.
 */
final class ItemEditor
{
    private function __construct(
        private readonly Document $document,
        private readonly Component $component,
    ) {}

    /**
     * Edits the master component of a calendar object: the event, task or
     * note without RECURRENCE-ID. Overrides of single occurrences are kept.
     */
    public static function forCalendar(VCalendar $calendar): self
    {
        $fallback = null;

        foreach ($calendar->getComponents() as $component) {
            if (! in_array($component->name, ['VEVENT', 'VTODO', 'VJOURNAL'], true)) {
                continue;
            }

            if (! isset($component->{'RECURRENCE-ID'})) {
                return new self($calendar, $component);
            }

            $fallback ??= $component;
        }

        if ($fallback === null) {
            throw new InvalidArgumentException('The calendar object has no event, task or note.');
        }

        // An object that holds only an override is edited as it is.
        return new self($calendar, $fallback);
    }

    public static function forCard(VCard $card): self
    {
        return new self($card, $card);
    }

    public function component(): Component
    {
        return $this->component;
    }

    public function value(string $name): ?string
    {
        $property = $this->component->select($name);
        $first = reset($property);

        return $first === false ? null : (string) $first;
    }

    /**
     * Replaces every instance of the property with one value, or removes it
     * when the value is null or an empty string.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function set(string $name, mixed $value, array $parameters = []): self
    {
        $this->component->remove($name);

        if ($value !== null && $value !== '') {
            $this->component->add($name, $value, $parameters);
        }

        return $this;
    }

    public function remove(string $name): self
    {
        $this->component->remove($name);

        return $this;
    }

    /**
     * Replaces a group of properties of one name (the emails or phones of a
     * card, the categories of a task) with the given values, in order.
     *
     * @param  list<array{value: mixed, parameters?: array<string, mixed>}>  $values
     */
    public function replaceAll(string $name, array $values): self
    {
        $this->component->remove($name);

        foreach ($values as $entry) {
            if ($entry['value'] === null || $entry['value'] === '') {
                continue;
            }

            $this->component->add($name, $entry['value'], $entry['parameters'] ?? []);
        }

        return $this;
    }

    /**
     * Marks the item as changed now: DTSTAMP and LAST-MODIFIED, and a higher
     * SEQUENCE, on a calendar component; REV on a card.
     */
    public function touch(): self
    {
        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if ($this->document instanceof VCard) {
            $this->set('REV', $now->format('Ymd\THis\Z'));

            return $this;
        }

        $sequence = (int) ($this->value('SEQUENCE') ?? 0);
        $this->set('DTSTAMP', $now);
        $this->set('LAST-MODIFIED', $now);
        $this->set('SEQUENCE', $sequence + 1);

        return $this;
    }

    public function serialize(): string
    {
        return $this->document->serialize();
    }
}
