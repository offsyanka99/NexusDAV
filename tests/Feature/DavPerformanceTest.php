<?php

namespace Tests\Feature;

use App\Models\Principal;
use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\Dav\CalendarBackend;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PDO;
use PDOStatement;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * PR 5b: findings R18 and R21 from the code review.
 */
class DavPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_depth_one_propfind_reads_property_storage_once_per_collection(): void
    {
        $ada = $this->makeUser('ada');
        $token = app(AppPasswordService::class)->issue($ada, 'phone')['token'];
        $instance = DB::table('calendarinstances')->where('principaluri', 'principals/ada')->first();
        $this->assertNotNull($instance);
        $backend = new CalendarBackend(DB::connection()->getPdo());

        foreach (range(1, 40) as $i) {
            $backend->createCalendarObject([(int) $instance->calendarid, (int) $instance->id], 'e'.$i.'.ics', $this->event('e'.$i));
        }

        $this->dav('PROPPATCH', '/dav/calendars/ada/default/e3.ics', $token, $this->proppatch('object-3'))->assertStatus(207);
        $this->dav('PROPPATCH', '/dav/calendars/ada/default/', $token, $this->proppatch('calendar'))->assertStatus(207);

        $pdo = DB::connection()->getPdo();
        CountingStatement::$queries = [];
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CountingStatement::class]);

        try {
            $listing = $this->dav('PROPFIND', '/dav/calendars/ada/default/', $token, $this->propfind(), '1');
        } finally {
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
        }

        $listing->assertStatus(207);
        $propertyQueries = array_filter(CountingStatement::$queries, fn (string $sql): bool => str_contains($sql, 'propertystorage'));
        $this->assertLessThanOrEqual(2, count($propertyQueries), implode("\n", $propertyQueries));

        $body = $this->body($listing);
        $this->assertStringContainsString('>object-3<', $body);
        $this->assertStringContainsString('>calendar<', $body);
        // Only the two resources with a value carry one; the rest report 404.
        $this->assertSame(2, preg_match_all('/xmlns:\w+="urn:nexusdav:test">[^<]/', $body));
    }

    public function test_sibling_collections_keep_their_own_dead_properties(): void
    {
        $ada = $this->makeUser('ada');
        $token = app(AppPasswordService::class)->issue($ada, 'phone')['token'];

        $this->dav('MKCALENDAR', '/dav/calendars/ada/Work', $token)->assertStatus(201);
        $this->dav('MKCALENDAR', '/dav/calendars/ada/work', $token)->assertStatus(201);
        $this->dav('PROPPATCH', '/dav/calendars/ada/Work/', $token, $this->proppatch('upper'))->assertStatus(207);
        $this->dav('PROPPATCH', '/dav/calendars/ada/work/', $token, $this->proppatch('lower'))->assertStatus(207);

        $upper = $this->body($this->dav('PROPFIND', '/dav/calendars/ada/Work/', $token, $this->propfind(), '0'));
        $lower = $this->body($this->dav('PROPFIND', '/dav/calendars/ada/work/', $token, $this->propfind(), '0'));

        $this->assertStringContainsString('>upper<', $upper);
        $this->assertStringNotContainsString('>lower<', $upper);
        $this->assertStringContainsString('>lower<', $lower);
        $this->assertStringNotContainsString('>upper<', $lower);

        $this->dav('DELETE', '/dav/calendars/ada/Work/', $token)->assertNoContent();
        $this->assertStringContainsString('>lower<', $this->body($this->dav('PROPFIND', '/dav/calendars/ada/work/', $token, $this->propfind(), '0')));
        $this->assertSame(0, DB::table('propertystorage')->where('path', 'calendars/ada/Work')->count());
    }

    public function test_boot_backfill_only_visits_users_that_need_it(): void
    {
        $ready = $this->makeUser('ready');
        $missing = $this->makeUser('missing');
        Principal::query()->where('user_id', $missing->getKey())->delete();

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(UserService::class)->backfillHomeCollections();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertNotNull(Principal::query()->where('user_id', $missing->getKey())->first());

        foreach ($queries as $query) {
            $this->assertNotContains($ready->getKey(), $query['bindings'], $query['query']);
        }
    }

    private function makeUser(string $username): User
    {
        return app(UserService::class)->create([
            'username' => $username,
            'display_name' => ucfirst($username),
            'email' => $username.'@example.com',
        ]);
    }

    private function dav(string $method, string $uri, string $token, ?string $body = null, string $depth = '0'): TestResponse
    {
        $username = explode('/', trim(str_replace('/dav/calendars/', '', $uri), '/'))[0];

        return $this->call($method, $uri, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Basic '.base64_encode($username.':'.$token),
            'HTTP_DEPTH' => $depth,
            'CONTENT_TYPE' => 'application/xml',
        ], $body);
    }

    private function body(TestResponse $response): string
    {
        if ($response->baseResponse instanceof StreamedResponse) {
            return $response->streamedContent();
        }

        return (string) $response->getContent();
    }

    private function proppatch(string $value): string
    {
        return '<?xml version="1.0"?><d:propertyupdate xmlns:d="DAV:" xmlns:x="urn:nexusdav:test">'
            .'<d:set><d:prop><x:tag>'.$value.'</x:tag></d:prop></d:set></d:propertyupdate>';
    }

    private function propfind(): string
    {
        return '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:x="urn:nexusdav:test">'
            .'<d:prop><d:getetag/><x:tag/></d:prop></d:propfind>';
    }

    private function event(string $uid): string
    {
        return implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//NexusDAV//EN',
            'BEGIN:VEVENT', 'UID:'.$uid.'@nexusdav.test', 'DTSTAMP:20261101T120000Z',
            'DTSTART:20261102T130000Z', 'SUMMARY:Batch', 'END:VEVENT', 'END:VCALENDAR', '',
        ]);
    }
}

/**
 * Records every statement sabre's backends execute through Laravel's PDO.
 */
class CountingStatement extends PDOStatement
{
    /** @var list<string> */
    public static array $queries = [];

    protected function __construct() {}

    public function execute(?array $params = null): bool
    {
        self::$queries[] = $this->queryString;

        return parent::execute($params);
    }
}
