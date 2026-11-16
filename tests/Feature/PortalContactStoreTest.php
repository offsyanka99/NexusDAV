<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Dav\SharingService;
use App\Services\Portal\BatchResult;
use App\Services\Portal\ContactReader;
use App\Services\Portal\ContactStore;
use App\Services\Portal\Exceptions\AlreadyExists;
use App\Services\Portal\Exceptions\ChangedElsewhere;
use App\Services\Portal\Exceptions\InvalidItem;
use App\Services\Portal\Exceptions\NotFound;
use App\Services\Portal\Exceptions\ReadOnlyCollection;
use App\Services\Portal\ItemEditor;
use App\Services\Portal\ItemStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Sabre\VObject\Component\VCard;
use Tests\Support\PortalHelpers;
use Tests\TestCase;

/**
 * PR 50a, step 7: portal writes of contacts behave like a CardDAV client's
 * writes, and a card keeps the vCard version it has.
 */
class PortalContactStoreTest extends TestCase
{
    use PortalHelpers, RefreshDatabase;

    private const BOOK = '/dav/addressbooks/ada/default/';

    private User $ada;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config(['nexusdav.features.push' => true, 'app.url' => 'https://dav.example.org']);
        $this->fakeDelivery();
        $this->ada = $this->user('ada');
        $this->token = $this->token($this->ada);
    }

    public function test_store_writes_move_the_sync_token_queue_push_and_sync_to_a_client(): void
    {
        $subscription = $this->subscribe(self::BOOK, 'ada', $this->token);
        $before = $this->syncToken(self::BOOK, $this->ada, $this->token);
        Queue::fake();

        $created = $this->store()->create($this->ada, $this->book(), 'bob.vcf', $this->card('bob', 'Bob'));

        $this->assertSame('bob', $created->uid);
        $this->assertPushQueued($subscription->topic);
        $this->assertSame(['bob.vcf'], $this->syncedSince(self::BOOK, $this->ada, $this->token, $before));
        $this->assertSame($created->etag, $this->dav('GET', self::BOOK.'bob.vcf', 'ada', $this->token)->headers->get('ETag'));

        $this->putDav(self::BOOK.'bob.vcf', $this->ada, $this->token, $this->card('bob', 'Phone'))->assertNoContent();
        $this->assertRefused(ChangedElsewhere::class, fn () => $this->store()->update($this->ada, $this->book(), 'bob.vcf', $this->card('bob', 'Portal'), $created->etag));

        $current = app(ContactReader::class)->get($this->ada, $this->book(), 'bob.vcf');
        $updated = $this->store()->update($this->ada, $this->book(), 'bob.vcf', $this->card('bob', 'Portal'), $current->etag);
        $this->store()->delete($this->ada, $this->book(), 'bob.vcf', $updated->etag);
        $this->dav('GET', self::BOOK.'bob.vcf', 'ada', $this->token)->assertNotFound();
    }

    public function test_a_form_contact_is_vcard_3_and_an_imported_vcard_4_stays_4(): void
    {
        $card = new VCard(['VERSION' => '4.0', 'FN' => 'Form Person']);
        $card->add('N', ['Person', 'Form', '', '', '']);

        $created = $this->store()->createFromForm($this->ada, $this->book(), $card);

        $this->assertSame($created->uid.'.vcf', $created->uri);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $created->uid);
        $stored = app(ContactReader::class)->get($this->ada, $this->book(), $created->uri);
        $this->assertStringContainsString("VERSION:3.0\r\n", $stored->data);

        $four = "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:urn:uuid:4d2c\r\nFN:Thunder Bird\r\nN:Bird;Thunder;;;\r\nX-THUNDERBIRD-ETAG:7\r\nEND:VCARD\r\n";
        $imported = $this->store()->create($this->ada, $this->book(), 'tb.vcf', $four);
        $this->assertSame('urn:uuid:4d2c', $imported->uid);

        // An edit keeps the version and every property the form does not edit.
        $item = app(ContactReader::class)->get($this->ada, $this->book(), 'tb.vcf');
        $editor = ItemEditor::forCard($item->card());
        $editor->set('FN', 'Thunder Bird Jr')->replaceAll('EMAIL', [['value' => 'tb@example.com', 'parameters' => ['TYPE' => 'work']], ['value' => '']])->touch();
        $this->store()->update($this->ada, $this->book(), 'tb.vcf', $editor->serialize(), $item->etag);

        $text = app(ContactReader::class)->get($this->ada, $this->book(), 'tb.vcf')->data;
        $this->assertStringContainsString("VERSION:4.0\r\n", $text);
        $this->assertStringContainsString('FN:Thunder Bird Jr', $text);
        $this->assertStringContainsString('EMAIL;TYPE=work:tb@example.com', $text);
        $this->assertStringContainsString('X-THUNDERBIRD-ETAG:7', $text);
        $this->assertStringContainsString('REV:', $text);
    }

    public function test_refusals_read_only_share_other_user_invalid_data_and_uid_conflicts(): void
    {
        $bob = $this->user('bob');
        $this->shareAddressBook($this->ada, $bob, SharingService::READ);

        $this->assertSame('This address book is read-only.', $this->assertRefused(ReadOnlyCollection::class, fn () => $this->store()->create($bob, $this->book(), 'x.vcf', $this->card('x')))->title());
        $this->assertRefused(NotFound::class, fn () => $this->store()->create($this->ada, $this->addressBookId($bob), 'x.vcf', $this->card('x')));
        $this->assertRefused(NotFound::class, fn () => app(ContactReader::class)->get($this->user('cleo'), $this->book(), 'x.vcf'));

        $this->assertStringStartsWith('This resource only supports valid vCard or jCard data.', $this->assertRefused(InvalidItem::class, fn () => $this->store()->create($this->ada, $this->book(), 'x.vcf', 'nonsense'))->getMessage());
        $this->assertRefused(InvalidItem::class, fn () => $this->store()->create($this->ada, $this->book(), 'x.vcf', $this->event('x')));

        $this->store()->create($this->ada, $this->book(), 'a.vcf', $this->card('same'));
        $this->assertRefused(AlreadyExists::class, fn () => $this->store()->create($this->ada, $this->book(), 'b.vcf', $this->card('same')));
        $this->assertRefused(AlreadyExists::class, fn () => $this->store()->create($this->ada, $this->book(), 'a.vcf', $this->card('other')));

        // The sharee with read access still reads it.
        $this->assertStringContainsString('UID:same', app(ContactReader::class)->get($bob, $this->book(), 'a.vcf')->data);
    }

    public function test_a_batch_matches_stored_cards_by_uid(): void
    {
        $this->store()->create($this->ada, $this->book(), 'kept.vcf', $this->card('kept', 'Stored'));

        $skip = $this->store()->batch($this->ada, $this->book(), [$this->card('kept', 'Imported'), $this->card('new', 'New'), 'nonsense']);
        $this->assertSame([BatchResult::SKIPPED, BatchResult::CREATED, BatchResult::REFUSED], array_column($skip->items(), 'status'));
        $this->assertSame(['kept.vcf', 'new.vcf', null], array_column($skip->items(), 'uri'));

        $replace = $this->store()->batch($this->ada, $this->book(), [$this->card('kept', 'Imported')], ItemStore::REPLACE);
        $this->assertSame([BatchResult::UPDATED], array_column($replace->items(), 'status'));
        $this->assertStringContainsString('FN:Imported', app(ContactReader::class)->get($this->ada, $this->book(), 'kept.vcf')->data);

        $fail = $this->store()->batch($this->ada, $this->book(), [$this->card('kept')], ItemStore::FAIL);
        $this->assertInstanceOf(AlreadyExists::class, $fail->refused()[0]['error']);
        $this->assertSame(2, DB::table('cards')->where('addressbookid', $this->book())->count());

        $all = app(ContactReader::class)->all($this->ada, $this->book())->map(fn ($item) => $item->uri)->all();
        $this->assertSame(['kept.vcf', 'new.vcf'], $all);
    }

    private function book(): int
    {
        return $this->addressBookId($this->ada);
    }

    private function store(): ContactStore
    {
        return app(ContactStore::class);
    }
}
