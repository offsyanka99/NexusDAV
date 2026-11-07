<?php

namespace App\Services\Dav;

use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Sabre\CardDAV\Backend\PDO as SabreAddressBookBackend;
use Sabre\CardDAV\Plugin as CardDavPlugin;
use Sabre\DAV\Exception\MethodNotAllowed;
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
     * The principal's own books, then the books shared with them. A share is
     * the owner's book (its id, cards and token) under the sharee's name; the
     * entry has the sharee's own `uri`, name, description and principal, and
     * a `share-access` key that tells the home to build a shared node.
     *
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

        // Sharing off keeps the rows and leaves the sharee's copy out of the home.
        if (! filter_var(config('nexusdav.features.sharing', true), FILTER_VALIDATE_BOOLEAN)) {
            return $books;
        }

        $shares = DB::table('addressbookinstances as i')
            ->join('addressbooks as b', 'b.id', '=', 'i.addressbookid')
            ->where('i.principaluri', $principalUri)
            ->orderBy('i.id')
            ->get(['i.id as instance_id', 'i.uri', 'i.displayname', 'i.description', 'i.access', 'i.principaluri', 'b.id', 'b.synctoken']);

        foreach ($shares as $share) {
            $token = SyncToken::format((int) $share->id, (int) $share->synctoken);
            $books[] = [
                'id' => $share->id,
                'uri' => $share->uri,
                'principaluri' => $share->principaluri,
                '{DAV:}displayname' => $share->displayname,
                '{'.CardDavPlugin::NS_CARDDAV.'}addressbook-description' => $share->description,
                self::CTAG => SyncPlugin::SYNCTOKEN_PREFIX.$token,
                self::SYNC_TOKEN => $token,
                'share-instance-id' => (int) $share->instance_id,
                'share-access' => (int) $share->access,
            ];
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
        $row = DB::table('addressbooks')->where('id', $id)->first(['synctoken', 'synctoken_min']);

        if ($row === null) {
            return null;
        }

        $from = null;

        if (! SyncToken::isInitial($syncToken)) {
            $from = SyncToken::resolve($syncToken, $id, (int) $row->synctoken, (int) $row->synctoken_min);

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

    protected function addChange($addressBookId, $objectUri, $operation)
    {
        parent::addChange($addressBookId, $objectUri, $operation);

        app(SyncHistoryPruner::class)->afterWrite();
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function createAddressBook($principalUri, $url, array $properties)
    {
        DavLimits::assertName($url, 'The address book name');
        DavLimits::assertProperties($properties, DavLimits::ADDRESS_BOOK);

        // A received share is not in this table, so the unique key cannot
        // see it. Two children of one name would hide each other. Both tables
        // are read under the lock, so a race ends in 405, not a 500.
        HomeNamespace::lock((string) $principalUri);

        if (HomeNamespace::addressBookUriTaken((string) $principalUri, (string) $url)) {
            throw new MethodNotAllowed('The resource you tried to create already exists');
        }

        return parent::createAddressBook($principalUri, $url, $properties);
    }

    /**
     * The owner's book goes with its shares, and with the locks and
     * properties each sharee kept under their own path for it.
     */
    public function deleteAddressBook($addressBookId)
    {
        $shares = DB::table('addressbookinstances')->where('addressbookid', $addressBookId)->get(['principaluri', 'uri']);

        foreach ($shares as $share) {
            app(DavDataCleaner::class)->deletePath(SharingService::sharePath(false, (string) $share->principaluri, (string) $share->uri));
        }

        DB::table('addressbookinstances')->where('addressbookid', $addressBookId)->delete();

        parent::deleteAddressBook($addressBookId);
    }

    /**
     * Leaves a share: only the sharee's row goes, with the locks and
     * properties kept under their path. The owner's book and cards stay.
     */
    public function leaveShare(int $instanceId): void
    {
        DB::transaction(function () use ($instanceId): void {
            $share = DB::table('addressbookinstances')->where('id', $instanceId)->first(['principaluri', 'uri']);

            if ($share === null) {
                return;
            }

            DB::table('addressbookinstances')->where('id', $instanceId)->delete();
            app(DavDataCleaner::class)->deletePath(SharingService::sharePath(false, (string) $share->principaluri, (string) $share->uri));
        });
    }

    /**
     * The sharee's name and description. They are claimed here so no change
     * row is written and the owner's sync token does not move.
     */
    public function updateShare(int $instanceId, PropPatch $propPatch): void
    {
        $columns = [
            '{DAV:}displayname' => 'displayname',
            '{'.CardDavPlugin::NS_CARDDAV.'}addressbook-description' => 'description',
        ];

        $propPatch->handle(array_keys($columns), function (array $mutations) use ($instanceId, $columns): bool {
            $values = [];

            foreach ($mutations as $name => $value) {
                if (! is_scalar($value) && $value !== null) {
                    return false;
                }

                $values[$columns[$name]] = $value;
            }

            if ($values !== []) {
                DB::table('addressbookinstances')->where('id', $instanceId)->update($values);
            }

            return true;
        });
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
