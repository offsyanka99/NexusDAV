<?php

namespace App\Services\Portal\Exceptions;

use App\Services\Portal\CollectionRef;

/**
 * A write to a calendar or address book the user may not change, with the
 * reasons from PortalCollections::readOnlyReasons().
 */
final class ReadOnlyCollection extends PortalException
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(public readonly bool $calendar, public readonly array $reasons)
    {
        parent::__construct(($calendar ? 'The calendar' : 'The address book').' is read-only: '.implode(', ', $reasons).'.');
    }

    public static function for(CollectionRef $ref): self
    {
        return new self($ref->isCalendar(), $ref->readOnlyReasons);
    }

    public function title(): string
    {
        return $this->calendar ? __('This calendar is read-only.') : __('This address book is read-only.');
    }

    public function body(): ?string
    {
        if (in_array(CollectionRef::REASON_HOLIDAY, $this->reasons, true)) {
            return __('Holiday calendars are filled by the server.');
        }

        if (in_array(CollectionRef::REASON_READ_ONLY_SHARE, $this->reasons, true)) {
            return __('It is shared with you to read only.');
        }

        return null;
    }
}
