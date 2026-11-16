<?php

namespace App\Services\Portal;

use App\Models\User;
use App\Services\Dav\BinaryValue;
use App\Services\Portal\Exceptions\NotFound;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Contacts of an address book the user sees, read with the same access
 * check as the stores.
 */
final class ContactReader
{
    public function __construct(private PortalCollections $collections) {}

    public function get(User $user, int $addressBookId, string $uri): StoredItem
    {
        $ref = $this->collections->find($user, CollectionRef::ADDRESS_BOOK, $addressBookId);
        $row = DB::table('cards')->where('addressbookid', $ref->id)->where('uri', $uri)->first(['uri', 'etag', 'carddata']);

        if ($row === null) {
            throw new NotFound;
        }

        return new StoredItem($ref, (string) $row->uri, '"'.$row->etag.'"', BinaryValue::toString($row->carddata));
    }

    /**
     * Every card of the address book, read in chunks by id (export).
     *
     * @return LazyCollection<int, StoredItem>
     */
    public function all(User $user, int $addressBookId, int $chunk = 200): LazyCollection
    {
        $ref = $this->collections->find($user, CollectionRef::ADDRESS_BOOK, $addressBookId);

        return DB::table('cards')
            ->where('addressbookid', $ref->id)
            ->select(['id', 'uri', 'etag', 'carddata'])
            ->lazyById($chunk)
            ->map(static fn (object $row): StoredItem => new StoredItem($ref, (string) $row->uri, '"'.$row->etag.'"', BinaryValue::toString($row->carddata)));
    }
}
