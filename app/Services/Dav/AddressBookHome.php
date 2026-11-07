<?php

namespace App\Services\Dav;

use Sabre\CardDAV\AddressBook;
use Sabre\CardDAV\AddressBookHome as SabreAddressBookHome;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Sync\ISyncCollection;

final class AddressBookHome extends SabreAddressBookHome implements ISyncCollection
{
    use HomeSync;

    /**
     * sabre compares names with ==, which makes "1e1" and "10" the same child.
     */
    public function getChild($name)
    {
        foreach ($this->getChildren() as $child) {
            if ($name === $child->getName()) {
                return $child;
            }
        }

        throw new NotFound('Addressbook with name \''.$name.'\' could not be found');
    }

    /**
     * An owned book is sabre's node. A shared one is built here: sabre's would
     * grant everything to the sharee and delete the owner's book.
     */
    public function getChildren()
    {
        $children = [];

        foreach ($this->carddavBackend->getAddressBooksForUser($this->getOwner()) as $book) {
            $children[] = array_key_exists('share-access', $book)
                ? new AddressBookNode($this->carddavBackend, $book)
                : new AddressBook($this->carddavBackend, $book);
        }

        return $children;
    }
}
