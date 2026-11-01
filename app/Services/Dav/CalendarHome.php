<?php

namespace App\Services\Dav;

use Sabre\CalDAV\CalendarHome as SabreCalendarHome;
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

        return parent::getChild($name);
    }

    public function getChildren()
    {
        $children = [];

        foreach (parent::getChildren() as $child) {
            if ($this->schedulingNode($child->getName())) {
                continue;
            }

            $children[] = $child;
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
}
