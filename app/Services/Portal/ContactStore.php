<?php

namespace App\Services\Portal;

use App\Models\User;
use App\Services\Dav\AddressBookBackend;
use App\Services\Dav\BinaryValue;
use Illuminate\Support\Facades\DB;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Document;

/**
 * Contacts of the user's address books, written through AddressBookBackend
 * like a CardDAV PUT or DELETE. A card keeps the vCard version it has; only
 * a new contact from a form is written as vCard 3.0 (Decision 13).
 */
final class ContactStore extends ItemStore
{
    /**
     * A new contact from a form: vCard 3.0, a random UUID as its UID and
     * <uid>.vcf as its URI.
     */
    public function createFromForm(User $user, int $addressBookId, VCard $card): ItemResult
    {
        if ($card->getDocumentType() !== Document::VCARD30) {
            $card = $card->convert(Document::VCARD30);
        }

        $uid = $this->newUid();
        $card->remove('UID');
        $card->add('UID', $uid);

        return $this->create($user, $addressBookId, $uid.'.vcf', $card->serialize());
    }

    /**
     * The UID of a stored card, read from its text without parsing the
     * whole card: cards have no UID column.
     */
    public static function uidOf(string $data): ?string
    {
        $unfolded = (string) preg_replace("/\r?\n[ \t]/", '', $data);

        if (preg_match('/^(?:[A-Za-z0-9-]+\.)?UID(?:;[^:\r\n]*)?:([^\r\n]*)/mi', $unfolded, $match) !== 1) {
            return null;
        }

        $uid = trim($match[1]);

        return $uid === '' ? null : $uid;
    }

    protected function type(): string
    {
        return CollectionRef::ADDRESS_BOOK;
    }

    protected function extension(): string
    {
        return '.vcf';
    }

    /**
     * The UID as it is written in the card, so it compares with uidOf() of
     * the stored cards.
     */
    protected function validate(string $data, CollectionRef $ref): ValidatedItem
    {
        $item = $this->validator->card($data);

        return new ValidatedItem($item->data, self::uidOf($item->data) ?? $item->uid, $item->component);
    }

    protected function current(CollectionRef $ref, string $uri): ?array
    {
        $row = DB::table('cards')->where('addressbookid', $ref->id)->where('uri', $uri)->first(['etag', 'carddata']);

        if ($row === null) {
            return null;
        }

        return ['etag' => '"'.$row->etag.'"', 'uid' => self::uidOf(BinaryValue::toString($row->carddata))];
    }

    protected function byUid(CollectionRef $ref): array
    {
        $cards = [];

        foreach ($this->cards($ref) as $row) {
            $uid = self::uidOf(BinaryValue::toString($row->carddata));

            if ($uid !== null && ! isset($cards[$uid])) {
                $cards[$uid] = ['uri' => (string) $row->uri, 'etag' => '"'.$row->etag.'"'];
            }
        }

        return $cards;
    }

    protected function storedUris(CollectionRef $ref): array
    {
        return DB::table('cards')->where('addressbookid', $ref->id)->pluck('uri')->map(static fn (mixed $uri): string => (string) $uri)->all();
    }

    protected function uidTakenBy(CollectionRef $ref, string $uid, string $exceptUri): ?string
    {
        foreach ($this->cards($ref) as $row) {
            if ((string) $row->uri !== $exceptUri && self::uidOf(BinaryValue::toString($row->carddata)) === $uid) {
                return (string) $row->uri;
            }
        }

        return null;
    }

    protected function backendCreate(CollectionRef $ref, string $uri, string $data): string
    {
        return (string) $this->backend()->createCard($ref->id, $uri, $data);
    }

    protected function backendUpdate(CollectionRef $ref, string $uri, string $data): string
    {
        return (string) $this->backend()->updateCard($ref->id, $uri, $data);
    }

    protected function backendDelete(CollectionRef $ref, string $uri): void
    {
        $this->backend()->deleteCard($ref->id, $uri);
    }

    /**
     * @return iterable<int, object{id: int, uri: string, etag: string, carddata: mixed}>
     */
    private function cards(CollectionRef $ref): iterable
    {
        return DB::table('cards')
            ->where('addressbookid', $ref->id)
            ->select(['id', 'uri', 'etag', 'carddata'])
            ->lazyById(500);
    }

    private function backend(): AddressBookBackend
    {
        return app(AddressBookBackend::class);
    }
}
