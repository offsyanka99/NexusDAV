<?php

namespace App\Services\Dav;

use Sabre\DAV\PropPatch;
use Sabre\DAVACL\PrincipalBackend\BackendInterface;

/**
 * Hides every principal except the authenticated user.
 * Listing and principal-property-search would otherwise expose the user list.
 */
final class OwnerOnlyPrincipalBackend implements BackendInterface
{
    public function __construct(
        private PrincipalBackend $inner,
        private DavAuthBackend $auth,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPrincipalsByPrefix($prefixPath)
    {
        $own = $this->ownUri();

        if ($own === null) {
            return [];
        }

        $rows = [];

        foreach ($this->inner->getPrincipalsByPrefix($prefixPath) as $principal) {
            if (($principal['uri'] ?? null) === $own) {
                $rows[] = $principal;
            }
        }

        return $rows;
    }

    /**
     * Another user's principal is reported as missing, so its calendar and
     * address book homes return 404 like an unknown name, not 403.
     *
     * @return array<string, mixed>|null
     */
    public function getPrincipalByPath($path)
    {
        $own = $this->ownUri();

        if ($own === null || rtrim((string) $path, '/') !== $own) {
            return null;
        }

        return $this->inner->getPrincipalByPath($own);
    }

    public function updatePrincipal($path, PropPatch $propPatch)
    {
        $this->inner->updatePrincipal($path, $propPatch);
    }

    /**
     * @param  array<string, mixed>  $searchProperties
     * @return list<string>
     */
    public function searchPrincipals($prefixPath, array $searchProperties, $test = 'allof')
    {
        $own = $this->ownUri();

        if ($own === null) {
            return [];
        }

        $matches = $this->inner->searchPrincipals($prefixPath, $searchProperties, $test);

        return in_array($own, $matches, true) ? [$own] : [];
    }

    /**
     * @return string|null
     */
    public function findByUri($uri, $principalPrefix)
    {
        $found = $this->inner->findByUri($uri, $principalPrefix);
        $own = $this->ownUri();

        if (! is_string($found) || $found !== $own) {
            return null;
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    public function getGroupMemberSet($principal)
    {
        return $this->inner->getGroupMemberSet($principal);
    }

    /**
     * @return list<string>
     */
    public function getGroupMembership($principal)
    {
        return $this->inner->getGroupMembership($principal);
    }

    /**
     * @param  list<string>  $members
     */
    public function setGroupMemberSet($principal, array $members)
    {
        $this->inner->setGroupMemberSet($principal, $members);
    }

    private function ownUri(): ?string
    {
        $username = $this->auth->username();

        if (! is_string($username) || $username === '') {
            return null;
        }

        return 'principals/'.$username;
    }
}
