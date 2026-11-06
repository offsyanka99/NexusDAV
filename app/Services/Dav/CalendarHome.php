<?php

namespace App\Services\Dav;

use Sabre\CalDAV\Backend\SubscriptionSupport;
use Sabre\CalDAV\CalendarHome as SabreCalendarHome;
use Sabre\CalDAV\Subscriptions\Subscription;
use Sabre\DAV\Exception\MethodNotAllowed;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\MkCol;
use Sabre\DAV\Sync\ISyncCollection;

final class CalendarHome extends SabreCalendarHome implements ISyncCollection
{
    use HomeSync;

    /** @var list<string> */
    public const RESERVED_NAMES = ['inbox', 'outbox', 'notifications'];

    /**
     * sabre would also list an inbox and an outbox. The inbox grants read to
     * every authenticated user, and scheduling is not served.
     */
    public function getChild($name)
    {
        if ($this->schedulingNode($name)) {
            throw new NotFound('Node with name \''.$name.'\' could not be found');
        }

        foreach ($this->caldavBackend->getCalendarsForUser($this->principalUri()) as $calendar) {
            if (is_array($calendar) && ($calendar['uri'] ?? null) === $name) {
                return new CalendarNode($this->caldavBackend, $calendar);
            }
        }

        // Subscriptions, or NotFound. Calendars are built above: sabre's own
        // child would be a SharedCalendar, whose ACL grants share and proxy.
        return parent::getChild($name);
    }

    public function getChildren()
    {
        $children = [];

        foreach ($this->caldavBackend->getCalendarsForUser($this->principalUri()) as $calendar) {
            if (! is_array($calendar) || ! is_string($calendar['uri'] ?? null) || $this->schedulingNode($calendar['uri'])) {
                continue;
            }

            $children[] = new CalendarNode($this->caldavBackend, $calendar);
        }

        if ($this->caldavBackend instanceof SubscriptionSupport) {
            foreach ($this->caldavBackend->getSubscriptionsForUser($this->principalUri()) as $subscription) {
                if (is_array($subscription) && is_string($subscription['uri'] ?? null) && ! $this->schedulingNode($subscription['uri'])) {
                    $children[] = new Subscription($this->caldavBackend, $subscription);
                }
            }
        }

        return $children;
    }

    /**
     * getChild hides these names, and sabre treats NotFound as free to create.
     * A row created here would stay unreachable.
     */
    public function createExtendedCollection($name, MkCol $mkCol)
    {
        if ($this->schedulingNode($name)) {
            throw new MethodNotAllowed('This name is reserved.');
        }

        parent::createExtendedCollection($name, $mkCol);
    }

    private function schedulingNode(string $name): bool
    {
        return in_array($name, self::RESERVED_NAMES, true);
    }

    private function principalUri(): string
    {
        $uri = $this->principalInfo['uri'] ?? '';

        return is_string($uri) ? $uri : '';
    }
}
