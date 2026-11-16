<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Dav\CollectionService;
use App\Services\Dav\SharingService;
use App\Services\Portal\CalendarReader;
use App\Services\Portal\Occurrence;
use App\Services\Portal\OccurrenceList;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Sabre\VObject\Settings;
use Tests\Support\PortalHelpers;
use Tests\TestCase;

/**
 * PR 50b, step 6: the occurrences of a time range, recurring events
 * expanded in the user's time zone.
 */
class CalendarReaderTest extends TestCase
{
    use PortalHelpers, RefreshDatabase;

    private const CALENDAR = '/dav/calendars/ada/default/';

    private User $ada;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ada = $this->user('ada');
        $this->ada->forceFill(['timezone' => 'Europe/Berlin'])->save();
        $this->token = $this->token($this->ada);
    }

    public function test_a_series_across_a_dst_change_with_a_deleted_and_a_moved_occurrence(): void
    {
        $this->putDav(self::CALENDAR.'dst.ics', $this->ada, $this->token, $this->dstSeries())->assertCreated();

        $list = $this->between('2026-03-26', '2026-04-02');

        // 09:00 in Berlin before and after the clocks change; March 29 deleted;
        // March 30 moved to 12:00.
        $this->assertSame([
            '2026-03-27 09:00 Stand-up',
            '2026-03-28 09:00 Stand-up',
            '2026-03-30 12:00 Stand-up (moved)',
            '2026-03-31 09:00 Stand-up',
        ], $this->summary($list->occurrences));
        $this->assertSame(['+01:00', '+01:00', '+02:00', '+02:00'], array_map(fn (Occurrence $o) => $o->start->format('P'), $list->occurrences));
        $this->assertSame('2026-03-27 10:00', $list->occurrences[0]->end->format('Y-m-d H:i'));
        $this->assertTrue($list->occurrences[0]->recurring);
        $this->assertSame('20260327T080000Z', $list->occurrences[0]->recurrenceId);
        $this->assertFalse($list->truncated);
        $this->assertFalse($list->occurrences[0]->readOnly);
        $this->assertSame('dst.ics', $list->occurrences[0]->uri);
    }

    public function test_all_day_and_floating_events_are_read_in_the_users_zone(): void
    {
        $this->putDav(self::CALENDAR.'day.ics', $this->ada, $this->token, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VEVENT\r\nUID:day\r\nDTSTAMP:20260101T000000Z\r\nDTSTART;VALUE=DATE:20260401\r\nDTEND;VALUE=DATE:20260403\r\nSUMMARY:Trip\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n")->assertCreated();
        $this->putDav(self::CALENDAR.'float.ics', $this->ada, $this->token, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VEVENT\r\nUID:float\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260401T090000\r\nDTEND:20260401T093000\r\nSUMMARY:Pills\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n")->assertCreated();

        $berlin = $this->between('2026-04-01', '2026-04-02');
        $this->assertSame(['2026-04-01 00:00 Trip', '2026-04-01 09:00 Pills'], $this->summary($berlin->occurrences));
        $this->assertTrue($berlin->occurrences[0]->allDay);
        $this->assertSame('2026-04-03 00:00', $berlin->occurrences[0]->end->format('Y-m-d H:i'));
        $this->assertSame('Europe/Berlin', $berlin->occurrences[1]->start->getTimezone()->getName());
        $this->assertFalse($berlin->occurrences[1]->recurring);
        $this->assertNull($berlin->occurrences[1]->recurrenceId);

        // In Tokyo the same wall-clock times, at other moments.
        $this->ada->forceFill(['timezone' => 'Asia/Tokyo'])->save();
        $tokyo = $this->between('2026-04-01', '2026-04-02');
        $this->assertSame(['2026-04-01 00:00 Trip', '2026-04-01 09:00 Pills'], $this->summary($tokyo->occurrences));
        $this->assertSame('+09:00', $tokyo->occurrences[1]->start->format('P'));
    }

    public function test_a_range_that_starts_or_ends_inside_an_event_returns_it(): void
    {
        $this->putDav(self::CALENDAR.'long.ics', $this->ada, $this->token, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VEVENT\r\nUID:long\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260501T080000Z\r\nDTEND:20260501T120000Z\r\nSUMMARY:Workshop\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n")->assertCreated();

        $reader = app(CalendarReader::class);
        $at = fn (string $time) => CarbonImmutable::parse($time, 'UTC');

        $this->assertCount(1, $reader->between($this->ada, $at('2026-05-01 10:00'), $at('2026-05-01 18:00'))->occurrences);
        $this->assertCount(1, $reader->between($this->ada, $at('2026-05-01 00:00'), $at('2026-05-01 09:00'))->occurrences);
        $this->assertCount(0, $reader->between($this->ada, $at('2026-05-01 12:00'), $at('2026-05-01 18:00'))->occurrences);
        $this->assertCount(0, $reader->between($this->ada, $at('2026-05-01 00:00'), $at('2026-05-01 08:00'))->occurrences);
        $this->assertCount(0, $reader->between($this->ada, $at('2026-05-01 18:00'), $at('2026-05-01 10:00'))->occurrences);
    }

    public function test_shared_calendars_are_read_flagged_read_only_and_another_users_are_not(): void
    {
        $bob = $this->user('bob');
        $bobToken = $this->token($bob);
        $cleo = $this->user('cleo');
        $cleoToken = $this->token($cleo);
        $this->putDav('/dav/calendars/bob/default/b.ics', $bob, $bobToken, $this->event('b', 'Bob shares this'))->assertCreated();
        $this->putDav('/dav/calendars/cleo/default/c.ics', $cleo, $cleoToken, $this->event('c', 'Cleo keeps this'))->assertCreated();
        $this->putDav(self::CALENDAR.'a.ics', $this->ada, $this->token, $this->event('a', 'Mine'))->assertCreated();
        $this->shareCalendar($bob, $this->ada, SharingService::READ);

        $list = app(CalendarReader::class)->between($this->ada, CarbonImmutable::parse('2026-11-01', 'UTC'), CarbonImmutable::parse('2026-11-03', 'UTC'));

        $titles = array_map(fn (Occurrence $o) => [$o->title, $o->readOnly], $list->occurrences);
        $this->assertEqualsCanonicalizing([['Mine', false], ['Bob shares this', true]], $titles);

        $only = app(CalendarReader::class)->between($this->ada, CarbonImmutable::parse('2026-11-01', 'UTC'), CarbonImmutable::parse('2026-11-03', 'UTC'), [$this->calendarId($bob), $this->calendarId($cleo)]);
        $this->assertSame(['Bob shares this'], array_map(fn (Occurrence $o) => $o->title, $only->occurrences));

        config(['nexusdav.features.caldav' => false]);
        $this->assertSame([], app(CalendarReader::class)->between($this->ada, CarbonImmutable::parse('2026-11-01', 'UTC'), CarbonImmutable::parse('2026-11-03', 'UTC'))->occurrences);
    }

    public function test_an_open_ended_series_is_cut_at_the_cap(): void
    {
        // A year is the longest range, so the cap is reached with hourly rules
        // (two daily rules over a year stay under 2,000).
        foreach (['x', 'y'] as $uid) {
            $this->putDav(self::CALENDAR.$uid.'.ics', $this->ada, $this->token, $this->openSeries($uid, '20260101T080000Z', 'HOURLY', "Hourly $uid"))->assertCreated();
        }

        $list = $this->between('2026-01-01', '2026-04-01');

        $this->assertTrue($list->truncated);
        $this->assertCount(CalendarReader::MAX_OCCURRENCES, $list->occurrences);
        $this->assertSame('2026-01-01 09:00', $list->occurrences[0]->start->format('Y-m-d H:i'));
        $this->assertSame('2026-01-01 10:00', $list->occurrences[0]->end->format('Y-m-d H:i'));
    }

    public function test_a_daily_series_that_started_years_before_the_range_is_shown(): void
    {
        // vobject counts from DTSTART. Ten years of a daily event is past its
        // own 3,500 ceiling, and used to vanish from every later week.
        $this->putDav(self::CALENDAR.'standup.ics', $this->ada, $this->token, $this->openSeries('standup', '20160406T070000Z', 'DAILY', 'Stand-up'))->assertCreated();

        $list = $this->between('2026-04-06', '2026-04-13');

        $this->assertFalse($list->truncated);
        $this->assertSame([
            '2026-04-06 09:00 Stand-up',
            '2026-04-07 09:00 Stand-up',
            '2026-04-08 09:00 Stand-up',
            '2026-04-09 09:00 Stand-up',
            '2026-04-10 09:00 Stand-up',
            '2026-04-11 09:00 Stand-up',
            '2026-04-12 09:00 Stand-up',
        ], $this->summary($list->occurrences));
    }

    public function test_an_hourly_series_that_started_months_before_the_range_is_shown(): void
    {
        $this->putDav(self::CALENDAR.'hourly.ics', $this->ada, $this->token, $this->openSeries('hourly', '20260101T080000Z', 'HOURLY', 'Hourly'))->assertCreated();

        $list = $this->between('2026-06-15', '2026-06-16');

        $this->assertFalse($list->truncated);
        $this->assertCount(24, $list->occurrences);
        $this->assertSame('2026-06-15 00:00 Hourly', $this->summary($list->occurrences)[0]);
        $this->assertSame('2026-06-15 23:00 Hourly', $this->summary($list->occurrences)[23]);
    }

    public function test_a_series_past_the_recurrence_limit_is_reported_and_the_limit_is_restored(): void
    {
        // About 130,000 minutes from March to June, past RECURRENCE_LIMIT.
        // expand() throws with nothing to show; the other event stays.
        $before = Settings::$maxRecurrences;
        $this->putDav(self::CALENDAR.'pulse.ics', $this->ada, $this->token, $this->openSeries('pulse', '20260301T000000Z', 'MINUTELY', 'Pulse', 'PT1M'))->assertCreated();
        $this->putDav(self::CALENDAR.'kept.ics', $this->ada, $this->token, $this->timed('kept', '20260601T100000Z', 'Still here'))->assertCreated();

        $list = $this->between('2026-06-01', '2026-06-02');

        $this->assertTrue($list->truncated);
        $this->assertSame(['2026-06-01 12:00 Still here'], $this->summary($list->occurrences));
        $this->assertSame($before, Settings::$maxRecurrences);
    }

    public function test_an_earlier_event_in_a_later_calendar_is_kept_when_the_cap_is_full(): void
    {
        // The hourly calendar is expanded first (lower calendarorder). Its
        // occurrences fill the cap; the early event is still merged in.
        app(CollectionService::class)->createCalendar($this->ada, 'later', 'Later', null, '#123456', true, true);
        DB::table('calendarinstances')->where('principaluri', $this->ada->principal_uri)->where('uri', 'default')->update(['calendarorder' => 0]);
        DB::table('calendarinstances')->where('principaluri', $this->ada->principal_uri)->where('uri', 'later')->update(['calendarorder' => 1]);
        $this->putDav(self::CALENDAR.'hourly.ics', $this->ada, $this->token, $this->openSeries('hourly', '20260101T080000Z', 'HOURLY', 'Hourly'))->assertCreated();
        $this->putDav('/dav/calendars/ada/later/early.ics', $this->ada, $this->token, $this->timed('early', '20260101T060000Z', 'Early'))->assertCreated();

        $list = $this->between('2026-01-01', '2026-04-01');

        $this->assertTrue($list->truncated);
        $this->assertCount(CalendarReader::MAX_OCCURRENCES, $list->occurrences);
        $this->assertSame('Early', $list->occurrences[0]->title);
        $this->assertSame('2026-01-01 07:00', $list->occurrences[0]->start->format('Y-m-d H:i'));
    }

    public function test_a_range_longer_than_a_year_is_refused(): void
    {
        $reader = app(CalendarReader::class);
        $days = CalendarReader::MAX_RANGE_DAYS;

        // 366 calendar days covers a leap year. The autumn clock change makes
        // that span an hour longer than 366*86400 seconds; it is still allowed.
        $utc = CarbonImmutable::parse('2024-01-01', 'UTC');
        $this->assertSame([], $reader->between($this->ada, $utc, $utc->addDays($days))->occurrences);
        $berlin = CarbonImmutable::parse('2026-10-01', 'Europe/Berlin');
        $this->assertSame([], $reader->between($this->ada, $berlin, $berlin->addDays($days))->occurrences);

        foreach ([$utc, $berlin] as $from) {
            try {
                $reader->between($this->ada, $from, $from->addDays($days)->addSecond());
                $this->fail('A range of '.$days.' days and one second was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('A calendar range cannot be longer than a year.', $exception->getMessage());
            }
        }
    }

    public function test_the_edges_of_a_day_are_the_users_for_floating_and_all_day_events(): void
    {
        $this->ada->forceFill(['timezone' => 'Asia/Tokyo'])->save();
        $this->putDav(self::CALENDAR.'late.ics', $this->ada, $this->token, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VEVENT\r\nUID:late\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260401T230000\r\nDTEND:20260401T233000\r\nSUMMARY:Late floating\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n")->assertCreated();
        $this->putDav(self::CALENDAR.'next.ics', $this->ada, $this->token, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VEVENT\r\nUID:next\r\nDTSTAMP:20260101T000000Z\r\nDTSTART;VALUE=DATE:20260402\r\nSUMMARY:Next day\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n")->assertCreated();
        $day = fn (string $zone, string $date) => app(CalendarReader::class)->between($this->ada, CarbonImmutable::parse($date, $zone), CarbonImmutable::parse($date, $zone)->addDay());

        $this->assertSame(['2026-04-01 23:00 Late floating'], $this->summary($day('Asia/Tokyo', '2026-04-01')->occurrences));

        $this->ada->forceFill(['timezone' => 'America/New_York'])->save();
        $this->assertSame(['2026-04-01 23:00 Late floating'], $this->summary($day('America/New_York', '2026-04-01')->occurrences));
        $this->assertSame(['2026-04-02 00:00 Next day'], $this->summary($day('America/New_York', '2026-04-02')->occurrences));
    }

    public function test_one_unreadable_occurrence_does_not_break_the_read(): void
    {
        $broken = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VEVENT\r\nUID:b\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260501T080000Z\r\nDTEND:20260501T090000Z\r\nRRULE:FREQ=DAILY;COUNT=3\r\nSUMMARY:Series\r\nEND:VEVENT\r\n"
            ."BEGIN:VEVENT\r\nUID:b\r\nDTSTAMP:20260101T000000Z\r\nRECURRENCE-ID:20260502T080000Z\r\nDTSTART:20260502T100000Z\r\nDURATION:bogus\r\nSUMMARY:Broken override\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $this->putDav(self::CALENDAR.'b.ics', $this->ada, $this->token, $broken)->assertCreated();
        $this->putDav(self::CALENDAR.'ok.ics', $this->ada, $this->token, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VEVENT\r\nUID:ok\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260502T120000Z\r\nSUMMARY:Fine\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n")->assertCreated();

        $titles = array_map(fn (Occurrence $o) => $o->title, $this->between('2026-05-01', '2026-05-04')->occurrences);

        $this->assertContains('Fine', $titles);
        $this->assertContains('Series', $titles);
    }

    private function openSeries(string $uid, string $start, string $freq, string $summary, string $duration = 'PT1H'): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:$start\r\nDURATION:$duration\r\nRRULE:FREQ=$freq\r\nSUMMARY:$summary\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    private function timed(string $uid, string $start, string $summary): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:$start\r\nDURATION:PT1H\r\nSUMMARY:$summary\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    private function between(string $from, string $to): OccurrenceList
    {
        return app(CalendarReader::class)->between($this->ada, CarbonImmutable::parse($from, 'Europe/Berlin'), CarbonImmutable::parse($to, 'Europe/Berlin'));
    }

    /**
     * @param  list<Occurrence>  $occurrences
     * @return list<string>
     */
    private function summary(array $occurrences): array
    {
        return array_map(fn (Occurrence $o) => $o->start->format('Y-m-d H:i').' '.$o->title, $occurrences);
    }
}
