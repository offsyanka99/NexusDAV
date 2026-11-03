<?php

namespace App\Services\Dav;

use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Sabre\CardDAV\Backend\PDO as SabreAddressBookBackend;
use Sabre\CardDAV\Plugin as CardDavPlugin;
use Sabre\DAV\PropPatch;
use Sabre\DAV\Sync\Plugin as SyncPlugin;

/**
 * sabre's PDO address book backend on Laravel's connection.
 * Card bytes are returned as a string on both SQLite and PostgreSQL.
 * Sync tokens and the ctag carry the address book's id (SyncToken).
 */
final class AddressBookBackend extends SabreAddressBookBackend
{
    private const SYNC_TOKEN = '{http://sabredav.org/ns}sync-token';

    private const CTAG = '{http://calendarserver.org/ns/}getctag';

    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo);
    }

    /**
     * Insert this address book when the principal does not already have the URI.
     * insertOrIgnore matches the unique (principaluri, uri) key, so a second
     * run does not abort a PostgreSQL transaction.
     */
    public function ensureAddressBook(string $principalUri, string $uri, string $displayName, ?string $description = null): void
    {
        DB::table('addressbooks')->insertOrIgnore([
            'uri' => $uri,
            'displayname' => $displayName,
            'description' => $description,
            'principaluri' => $principalUri,
            'synctoken' => 1,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAddressBooksForUser($principalUri)
    {
        $books = [];

        foreach (parent::getAddressBooksForUser($principalUri) as $book) {
            $token = SyncToken::format((int) $book['id'], (int) $book[self::SYNC_TOKEN]);
            $book[self::SYNC_TOKEN] = $token;
            $book[self::CTAG] = SyncPlugin::SYNCTOKEN_PREFIX.$token;
            $books[] = $book;
        }

        return $books;
    }

    /**
     * Checks the client's token against this address book before sabre reads
     * the change log, and returns the new token with the address book's id.
     *
     * @return array<string, mixed>|null
     */
    public function getChangesForAddressBook($addressBookId, $syncToken, $syncLevel, $limit = null)
    {
        $id = (int) $addressBookId;
        $current = DB::table('addressbooks')->where('id', $id)->value('synctoken');

        if ($current === null) {
            return null;
        }

        $from = null;

        if (! SyncToken::isInitial($syncToken)) {
            $from = SyncToken::resolve($syncToken, $id, (int) $current);

            if ($from === null) {
                return null;
            }
        }

        $result = parent::getChangesForAddressBook($addressBookId, $from === null ? null : (string) $from, $syncLevel, $limit);

        if (! is_array($result)) {
            return null;
        }

        $result['syncToken'] = SyncToken::format($id, (int) $result['syncToken']);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function createAddressBook($principalUri, $url, array $properties)
    {
        DavLimits::assertName($url, 'The address book name');
        DavLimits::assertProperties($properties, DavLimits::ADDRESS_BOOK);

        return parent::createAddressBook($principalUri, $url, $properties);
    }

    public function updateAddressBook($addressBookId, PropPatch $propPatch)
    {
        DavLimits::refuse($propPatch, DavLimits::ADDRESS_BOOK);

        parent::updateAddressBook($addressBookId, $propPatch);
    }

    public function createCard($addressBookId, $cardUri, $cardData)
    {
        DavLimits::assertName($cardUri, 'The resource name');

        return parent::createCard($addressBookId, $cardUri, $cardData);
    }

    public function createCollection(string $principalUri, string $uri, string $displayName, ?string $description): void
    {
        $properties = [
            '{DAV:}displayname' => $displayName,
        ];

        if ($description !== null) {
            $properties['{'.CardDavPlugin::NS_CARDDAV.'}addressbook-description'] = $description;
        }

        $this->createAddressBook($principalUri, $uri, $properties);
    }

    public function updateCollection(int $addressBookId, string $displayName, ?string $description): void
    {
        $patch = new PropPatch([
            '{DAV:}displayname' => $displayName,
            '{'.CardDavPlugin::NS_CARDDAV.'}addressbook-description' => $description,
        ]);
        $this->updateAddressBook((string) $addressBookId, $patch);

        if ($patch->commit() !== true) {
            throw new RuntimeException('The address book could not be saved.');
        }
    }

    /**
     * sabre documents an array, and returns false when the card is missing.
     *
     * @return array<string, mixed>|false
     */
    public function getCard($addressBookId, $cardUri)
    {
        /** @var mixed $card */
        $card = parent::getCard($addressBookId, $cardUri);

        if (! is_array($card) || ! array_key_exists('carddata', $card)) {
            return is_array($card) ? $card : false;
        }

        $card['carddata'] = BinaryValue::toString($card['carddata']);

        return $card;
    }

    /**
     * @param  list<string>  $uris
     * @return list<array<string, mixed>>
     */
    public function getMultipleCards($addressBookId, array $uris)
    {
        $cards = [];

        foreach (parent::getMultipleCards($addressBookId, $uris) as $card) {
            if (! is_array($card)) {
                continue;
            }

            if (array_key_exists('carddata', $card)) {
                $card['carddata'] = BinaryValue::toString($card['carddata']);
            }

            $cards[] = $card;
        }

        return $cards;
    }
}
