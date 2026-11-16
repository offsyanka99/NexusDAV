<?php

namespace App\Services\Portal;

/**
 * One calendar or address book as the signed-in user sees it: the
 * collection's id (calendars.id or addressbooks.id, which is unique among
 * the user's collections), the user's own instance (name, color, URI) and
 * access, and why it is read-only. Built by PortalCollections only.
 */
final class CollectionRef
{
    public const CALENDAR = 'calendar';

    public const ADDRESS_BOOK = 'addressbook';

    public const OWNER = 1;

    public const READ = 2;

    public const READ_WRITE = 3;

    public const REASON_READ_ONLY_SHARE = 'read-only share';

    public const REASON_HOLIDAY = 'holiday calendar';

    /**
     * @param  list<string>  $components  VEVENT, VTODO, VJOURNAL the calendar takes now (switches applied); empty for an address book
     * @param  list<string>  $readOnlyReasons  empty when the user may write
     */
    public function __construct(
        public readonly string $type,
        public readonly int $id,
        public readonly ?int $instanceId,
        public readonly string $principalUri,
        public readonly string $uri,
        public readonly string $name,
        public readonly ?string $color,
        public readonly ?string $description,
        public readonly array $components,
        public readonly int $access,
        public readonly bool $holiday,
        public readonly array $readOnlyReasons,
    ) {}

    public function isCalendar(): bool
    {
        return $this->type === self::CALENDAR;
    }

    public function isOwner(): bool
    {
        return $this->access === self::OWNER;
    }

    public function isWritable(): bool
    {
        return $this->readOnlyReasons === [];
    }

    public function takes(string $component): bool
    {
        return in_array($component, $this->components, true);
    }

    /**
     * The id the sabre backend takes: [calendar id, instance id] for a
     * calendar, the address book id for an address book.
     *
     * @return array{0: int, 1: int}|int
     */
    public function backendId(): array|int
    {
        return $this->isCalendar() ? [$this->id, (int) $this->instanceId] : $this->id;
    }

    /**
     * The user's DAV path of the collection, without /dav/.
     */
    public function davPath(): string
    {
        $user = substr($this->principalUri, strlen('principals/'));

        return ($this->isCalendar() ? 'calendars/' : 'addressbooks/').$user.'/'.$this->uri;
    }
}
