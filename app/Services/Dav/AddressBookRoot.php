<?php

namespace App\Services\Dav;

use Sabre\CardDAV\AddressBookRoot as SabreAddressBookRoot;
use Sabre\DAV\Exception\NotFound;

final class AddressBookRoot extends SabreAddressBookRoot
{
    /**
     * @param  array<string, mixed>  $principal
     */
    public function getChildForPrincipal(array $principal)
    {
        $uri = $principal['uri'] ?? null;

        if (! is_string($uri) || $uri === '') {
            throw new NotFound('Principal has no uri');
        }

        return new AddressBookHome($this->carddavBackend, $uri);
    }
}
