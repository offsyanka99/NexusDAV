<?php

namespace App\Services\Dav;

use Sabre\DAV\Exception\Forbidden;

/**
 * Read and write for the home's owner and nobody else, like sabre's calendar
 * home. The ACL method is refused (Decision 4 of the Version 1.x plan).
 */
trait FileAcl
{
    protected string $owner;

    public function getOwner()
    {
        return $this->owner;
    }

    public function getGroup()
    {
        return null;
    }

    /**
     * @return list<array{privilege: string, principal: string, protected: bool}>
     */
    public function getACL()
    {
        return [
            ['privilege' => '{DAV:}read', 'principal' => $this->owner, 'protected' => true],
            ['privilege' => '{DAV:}write', 'principal' => $this->owner, 'protected' => true],
        ];
    }

    /**
     * @param  array<int, mixed>  $acl
     */
    public function setACL(array $acl)
    {
        throw new Forbidden('Changing the ACL is not supported.');
    }

    public function getSupportedPrivilegeSet()
    {
        return null;
    }
}
