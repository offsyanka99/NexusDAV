<?php

namespace App\Services\Dav;

use RuntimeException;
use Sabre\CardDAV\AddressBook;
use Sabre\CardDAV\Card;
use Sabre\DAV\PropPatch;
use Sabre\DAV\Sharing\Plugin as SharingPlugin;

/**
 * An address book someone shared, in the sharee's home (docs/design/sharing.md).
 *
 * DAV:owner is the sharee, as for calendars, so sabre's own ACL (everything
 * for the owner) would let a read-only sharee write and delete the owner's
 * book. Privileges come from the share's access instead, never from that
 * owner property: read only gets read, and write-properties on the book for
 * the sharee's own name; read and write adds write. No share privilege.
 *
 * Cards and the change log stay on the owner's book, so a sharee's write moves
 * the owner's sync token. The sharee's name and description, and leaving the
 * share, touch only the sharee's own row.
 */
final class AddressBookNode extends AddressBook
{
    /**
     * @return list<array{privilege: string, principal: string, protected: true}>
     */
    public function getACL()
    {
        if ($this->access() === SharingPlugin::ACCESS_READWRITE) {
            return $this->aces(['{DAV:}read', '{DAV:}write']);
        }

        return $this->aces(['{DAV:}read', '{DAV:}write-properties']);
    }

    /**
     * @return list<array{privilege: string, principal: string, protected: true}>
     */
    public function getChildACL()
    {
        if ($this->access() === SharingPlugin::ACCESS_READWRITE) {
            return $this->aces(['{DAV:}read', '{DAV:}write']);
        }

        return $this->aces(['{DAV:}read']);
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

    /**
     * The sharee's name and description live on their own row. The owner's
     * book, its token and its change log are not touched.
     */
    public function propPatch(PropPatch $propPatch)
    {
        DavLimits::refuse($propPatch, DavLimits::ADDRESS_BOOK);

        $this->books()->updateShare($this->instanceId(), $propPatch);
    }

    /**
     * The stock delete removes the owner's address book. A sharee leaves the
     * share instead, and the owner's book and cards stay.
     */
    public function delete()
    {
        $this->books()->leaveShare($this->instanceId());
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

    private function access(): int
    {
        return (int) ($this->addressBookInfo['share-access'] ?? SharingPlugin::ACCESS_READ);
    }

    private function instanceId(): int
    {
        return (int) ($this->addressBookInfo['share-instance-id'] ?? 0);
    }

    private function books(): AddressBookBackend
    {
        if (! $this->carddavBackend instanceof AddressBookBackend) {
            throw new RuntimeException('A shared address book needs the address book backend.');
        }

        return $this->carddavBackend;
    }

    /**
     * @param  list<string>  $privileges
     * @return list<array{privilege: string, principal: string, protected: true}>
     */
    private function aces(array $privileges): array
    {
        $acl = [];

        foreach ($privileges as $privilege) {
            $acl[] = [
                'privilege' => $privilege,
                'principal' => (string) $this->addressBookInfo['principaluri'],
                'protected' => true,
            ];
        }

        return $acl;
    }
}
