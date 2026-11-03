<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AppPasswordService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Sabre\VObject\Reader;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * PR 13: the test-data seeder for the large-collection checklist step.
 */
class SeedTestDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_without_a_user_force_or_valid_counts_and_writes_nothing(): void
    {
        $this->makeUser('ada');

        $this->artisan('nexusdav:seed-test-data', ['username' => 'ada'])
            ->expectsOutputToContain('Run it again with --force.')
            ->assertFailed();

        $this->artisan('nexusdav:seed-test-data', ['username' => 'nobody', '--force' => true])
            ->expectsOutputToContain('No user named nobody.')
            ->assertFailed();

        $this->artisan('nexusdav:seed-test-data', ['username' => 'ada', '--events' => '-1', '--force' => true])->assertFailed();
        $this->artisan('nexusdav:seed-test-data', ['username' => 'ada', '--contacts' => '50001', '--force' => true])->assertFailed();
        $this->artisan('nexusdav:seed-test-data', ['username' => 'ada', '--events' => '0', '--contacts' => '0', '--force' => true])->assertFailed();

        $this->assertSame(0, DB::table('calendarobjects')->count());
        $this->assertSame(0, DB::table('cards')->count());
        $this->assertFalse(DB::table('calendarinstances')->where('uri', 'load-test')->exists());
        $this->assertFalse(DB::table('addressbooks')->where('uri', 'load-test')->exists());
    }

    public function test_the_username_argument_is_required(): void
    {
        $this->expectException(RuntimeException::class);

        $this->artisan('nexusdav:seed-test-data', ['--force' => true]);
    }

    public function test_it_creates_new_collections_that_a_client_lists_and_syncs_in_full(): void
    {
        $ada = $this->makeUser('ada');
        $token = app(AppPasswordService::class)->issue($ada, 'phone')['token'];

        $this->artisan('nexusdav:seed-test-data', ['username' => 'ada', '--events' => '60', '--contacts' => '40', '--force' => true])
            ->expectsOutputToContain('/dav/calendars/ada/load-test/ (60 events)')
            ->expectsOutputToContain('/dav/addressbooks/ada/load-test/ (40 contacts)')
            ->assertSuccessful();

        // The default collections stay empty.
        $this->assertSame(60, DB::table('calendarobjects')->count());
        $this->assertSame(40, DB::table('cards')->count());

        $calendar = $this->sync('/dav/calendars/ada/load-test/', 'ada', $token);
        $calendar->assertStatus(207);
        $this->assertSame(60, substr_count($this->body($calendar), '.ics</'));

        $book = $this->sync('/dav/addressbooks/ada/load-test/', 'ada', $token);
        $book->assertStatus(207);
        $this->assertSame(40, substr_count($this->body($book), '.vcf</'));

        $event = $this->dav('GET', '/dav/calendars/ada/load-test/load-test-10.ics', 'ada', $token)->assertOk();
        $parsed = Reader::read($this->body($event));
        $this->assertSame('FREQ=WEEKLY;COUNT=10', (string) $parsed->VEVENT->RRULE);
        $this->assertSame('load-test-event-10@nexusdav.test', (string) $parsed->VEVENT->UID);

        $allDay = Reader::read($this->body($this->dav('GET', '/dav/calendars/ada/load-test/load-test-25.ics', 'ada', $token)->assertOk()));
        $this->assertFalse($allDay->VEVENT->DTSTART->hasTime());

        $card = Reader::read($this->body($this->dav('GET', '/dav/addressbooks/ada/load-test/load-test-20.vcf', 'ada', $token)->assertOk()));
        $this->assertSame('Test Contact 20', (string) $card->FN);
        $this->assertNotNull($card->PHOTO);

        // A client that finished the first sync gets only later changes.
        $syncToken = $this->syncToken($calendar);
        $this->dav('DELETE', '/dav/calendars/ada/load-test/load-test-1.ics', 'ada', $token)->assertNoContent();
        $next = $this->sync('/dav/calendars/ada/load-test/', 'ada', $token, $syncToken);
        $this->assertSame(1, substr_count($this->body($next), '.ics</'));
        $this->assertStringContainsString('404', $this->body($next));
    }

    public function test_it_refuses_an_existing_test_collection_and_leaves_it_alone(): void
    {
        $this->makeUser('ada');

        $this->artisan('nexusdav:seed-test-data', ['username' => 'ada', '--events' => '5', '--contacts' => '0', '--force' => true])->assertSuccessful();

        $this->artisan('nexusdav:seed-test-data', ['username' => 'ada', '--events' => '5', '--contacts' => '0', '--force' => true])
            ->expectsOutputToContain('That URI is already used.')
            ->assertFailed();

        $this->assertSame(5, DB::table('calendarobjects')->count());

        $this->artisan('nexusdav:seed-test-data', ['username' => 'ada', '--events' => '3', '--contacts' => '0', '--calendar' => 'load-test-2', '--force' => true])->assertSuccessful();

        $this->assertSame(8, DB::table('calendarobjects')->count());
        $this->assertFalse(DB::table('addressbooks')->where('uri', 'load-test')->exists());
    }

    private function makeUser(string $username): User
    {
        return app(UserService::class)->create([
            'username' => $username,
            'display_name' => ucfirst($username),
            'email' => $username.'@example.com',
        ]);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function dav(string $method, string $uri, string $username, string $password, array $headers = [], ?string $body = null): TestResponse
    {
        $server = ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode($username.':'.$password)];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call($method, $uri, [], [], [], $server, $body);
    }

    private function sync(string $uri, string $username, string $password, ?string $token = null): TestResponse
    {
        $syncToken = $token === null ? '' : htmlspecialchars($token, ENT_XML1);

        return $this->dav('REPORT', $uri, $username, $password, ['Depth' => '0', 'Content-Type' => 'application/xml'], <<<XML
<?xml version="1.0" encoding="utf-8" ?>
<D:sync-collection xmlns:D="DAV:">
  <D:sync-token>{$syncToken}</D:sync-token>
  <D:sync-level>1</D:sync-level>
  <D:prop><D:getetag/></D:prop>
</D:sync-collection>
XML);
    }

    private function syncToken(TestResponse $response): string
    {
        $this->assertSame(1, preg_match('#<(?:[A-Za-z0-9]+:)?sync-token>([^<]+)</#', $this->body($response), $matches));

        return $matches[1];
    }

    private function body(TestResponse $response): string
    {
        return $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : (string) $response->getContent();
    }
}
