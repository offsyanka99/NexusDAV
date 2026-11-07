<?php

namespace App\Services\Dav;

use Sabre\CardDAV\AddressBook;
use Sabre\CardDAV\Card;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\PropPatch;

/**
 * An address book someone shared, in the sharee's home (docs/design/sharing.md).
 *
 * DAV:owner is the sharee, as for calendars, so sabre's own ACL (everything
 * for the owner) would let a read-only sharee write and delete the owner's
 * book. Privileges come from the share instead. Reading is all that is
 * served for now: writes, renaming and leaving a share arrive with PR 25, so
 * the node refuses each of them rather than passing it to the owner's book.
 */
final class AddressBookNode extends AddressBook
{
    /**
     * @return list<array{privilege: string, principal: string, protected: true}>
     */
    public function getACL()
    {
        return $this->readOnly();
    }

    /**
     * @return list<array{privilege: string, principal: string, protected: true}>
     */
    public function getChildACL()
    {
        return $this->readOnly();
    }

    /**
     * sabre builds this card without an ACL of its own, and a card falls back
     * to everything for the address book's principal, which is the sharee.
     */
    public function getChild($name)
    {
        $card = $this->carddavBackend->getCard($this->addressBookInfo['id'], $name);

        if (! $card) {
            return parent::getChild($name);
        }

        $card['acl'] = $this->getChildACL();

        return new Card($this->carddavBackend, $this->addressBookInfo, $card);
    }

    public function createFile($name, $data = null)
    {
        throw new Forbidden('This shared address book is read only.');
    }

    public function propPatch(PropPatch $propPatch)
    {
        throw new Forbidden('This shared address book is read only.');
    }

    /**
     * The stock delete removes the owner's address book.
     */
    public function delete()
    {
        throw new Forbidden('This shared address book is read only.');
    }

    /**
     * A bare change number cannot say which book it came from, and a share can
     * reuse a name the client already synced. Only the owner's own token form
     * (`<address book id>-<number>`) is read.
     */
    public function getChanges($syncToken, $syncLevel, $limit = null)
    {
        if (preg_match('/^[1-9][0-9]{0,9}$/', (string) $syncToken) === 1) {
            return null;
        }

        return parent::getChanges($syncToken, $syncLevel, $limit);
    }

    /**
     * @return list<array{privilege: string, principal: string, protected: true}>
     */
    private function readOnly(): array
    {
        return [[
            'privilege' => '{DAV:}read',
            'principal' => (string) $this->addressBookInfo['principaluri'],
            'protected' => true,
        ]];
    }
}
