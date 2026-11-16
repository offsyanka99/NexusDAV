<?php

namespace Tests\Feature;

use App\Models\ItemIndex;
use App\Models\User;
use App\Services\Dav\SharingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PortalHelpers;
use Tests\TestCase;

/**
 * PR 50b, step 5: the scopes apps list and search with.
 */
class ItemIndexQueryTest extends TestCase
{
    use PortalHelpers, RefreshDatabase;

    private User $ada;

    private User $bob;

    private string $adaToken;

    private string $bobToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ada = $this->user('ada');
        $this->bob = $this->user('bob');
        $this->adaToken = $this->token($this->ada);
        $this->bobToken = $this->token($this->bob);

        $this->putDav('/dav/calendars/ada/default/a.ics', $this->ada, $this->adaToken, $this->event('a', 'Встреча с клиентом'))->assertCreated();
        $this->putDav('/dav/calendars/ada/default/t.ics', $this->ada, $this->adaToken, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VTODO\r\nUID:t\r\nDTSTAMP:20260101T000000Z\r\nSUMMARY:Pay rent\r\nDUE:20260410T120000Z\r\nEND:VTODO\r\nEND:VCALENDAR\r\n")->assertCreated();
        $this->putDav('/dav/addressbooks/ada/default/p.vcf', $this->ada, $this->adaToken, "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:p\r\nFN:Ольга Смирнова\r\nN:Смирнова;Ольга;;;\r\nTEL:555-1234\r\nEND:VCARD\r\n")->assertCreated();
        $this->putDav('/dav/calendars/bob/default/b.ics', $this->bob, $this->bobToken, $this->event('b', 'Bob only meeting'))->assertCreated();
    }

    public function test_only_the_users_visible_collections_are_listed(): void
    {
        $this->assertSame(['a.ics', 'p.vcf', 't.ics'], $this->uris(ItemIndex::query()->visibleTo($this->ada)));
        $this->assertSame(['b.ics'], $this->uris(ItemIndex::query()->visibleTo($this->bob)));

        $this->shareCalendar($this->ada, $this->bob, SharingService::READ);
        $this->assertSame(['a.ics', 'b.ics', 't.ics'], $this->uris(ItemIndex::query()->visibleTo($this->bob)));

        config(['nexusdav.features.sharing' => false]);
        $this->assertSame(['b.ics'], $this->uris(ItemIndex::query()->visibleTo($this->bob)));

        config(['nexusdav.features.caldav' => false]);
        $this->assertSame(['p.vcf'], $this->uris(ItemIndex::query()->visibleTo($this->ada)));
        $this->assertSame([], $this->uris(ItemIndex::query()->visibleTo($this->bob)));
    }

    public function test_search_matches_cyrillic_in_any_case_and_phones_in_any_format(): void
    {
        $visible = fn () => ItemIndex::query()->visibleTo($this->ada);

        $this->assertSame(['a.ics'], $this->uris($visible()->search('ВСТРЕЧА')));
        $this->assertSame(['a.ics'], $this->uris($visible()->search('клиентом встреча')));
        $this->assertSame(['p.vcf'], $this->uris($visible()->search('ОЛЬГА')));
        $this->assertSame(['p.vcf'], $this->uris($visible()->search('555 1234')));
        $this->assertSame(['p.vcf'], $this->uris($visible()->search('5551234')));
        $this->assertSame(['p.vcf'], $this->uris($visible()->search('(555) 12-34')));
        $this->assertSame([], $this->uris($visible()->search('meeting')), 'Bob\'s event is not searched.');
        $this->assertSame([], $this->uris($visible()->search('100%')));
        $this->assertSame(['a.ics', 'p.vcf', 't.ics'], $this->uris($visible()->search('  ')));
        $this->assertSame(['p.vcf'], $this->uris($visible()->ofKind(ItemIndex::CONTACT)));
    }

    public function test_due_and_time_range_filters(): void
    {
        $visible = fn () => ItemIndex::query()->visibleTo($this->ada);
        $tokyo = 'Asia/Tokyo';

        $this->assertSame(['t.ics'], $this->uris($visible()->dueBetween(CarbonImmutable::parse('2026-04-10 00:00', 'UTC'), CarbonImmutable::parse('2026-04-11 00:00', 'UTC'))));
        $this->assertSame([], $this->uris($visible()->dueBetween(null, CarbonImmutable::parse('2026-04-10 21:00', $tokyo))));
        $this->assertSame(['t.ics'], $this->uris($visible()->dueBetween(null, CarbonImmutable::parse('2026-04-10 21:01', $tokyo))));

        // The event starts 2026-11-02 13:00 UTC and has no end.
        $this->assertSame(['a.ics'], $this->uris($visible()->overlapping(CarbonImmutable::parse('2026-11-02 00:00', 'UTC'), CarbonImmutable::parse('2026-11-03 00:00', 'UTC'))));
        $this->assertSame([], $this->uris($visible()->overlapping(CarbonImmutable::parse('2026-11-02 14:00', 'UTC'), null)));
        $this->assertSame([], $this->uris($visible()->overlapping(null, CarbonImmutable::parse('2026-11-02 13:00', 'UTC'))));
    }

    /**
     * @param  Builder<ItemIndex>  $query
     * @return list<string>
     */
    private function uris($query): array
    {
        return $query->orderBy('uri')->pluck('uri')->all();
    }
}
