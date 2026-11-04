<?php

namespace Tests\Feature;

use App\Services\AppPasswordService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * PR 16: a client stores the ETag from PUT and sends it back in If-Match. It
 * must be the ETag a later GET returns, or absent when the server changed the
 * data (RFC 4791 5.3.4), so the client fetches the stored version.
 */
class PutEtagTest extends TestCase
{
    use RefreshDatabase;

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();

        $ada = app(UserService::class)->create(['username' => 'ada', 'display_name' => 'Ada', 'email' => 'ada@example.com']);
        $this->password = app(AppPasswordService::class)->issue($ada, 'phone')['token'];
    }

    public function test_the_etag_from_put_is_the_etag_get_returns_for_each_kind_of_item(): void
    {
        $items = [
            'event' => ['/dav/calendars/ada/default/event.ics', 'text/calendar', $this->calendar('VEVENT', "DTSTART:20260101T100000Z\r\nDTEND:20260101T110000Z\r\n")],
            'task' => ['/dav/calendars/ada/default/task.ics', 'text/calendar', $this->calendar('VTODO', "DUE:20260102T100000Z\r\nSTATUS:NEEDS-ACTION\r\n")],
            'note' => ['/dav/calendars/ada/default/note.ics', 'text/calendar', $this->calendar('VJOURNAL', "DTSTART;VALUE=DATE:20260101\r\nDESCRIPTION:A note\r\n")],
            'contact' => ['/dav/addressbooks/ada/default/contact.vcf', 'text/vcard', "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:contact\r\nFN:Ada\r\nN:Lovelace;Ada;;;\r\nEMAIL:ada@example.com\r\nEND:VCARD\r\n"],
        ];

        foreach ($items as $kind => [$url, $type, $body]) {
            foreach (['create' => 201, 'update' => 204] as $step => $status) {
                $put = $this->dav('PUT', $url, ['CONTENT_TYPE' => $type], $step === 'update' ? str_replace('Ada', 'Augusta', $body) : $body);
                $put->assertStatus($status);
                $etag = $put->headers->get('ETag');

                $get = $this->dav('GET', $url)->assertOk();

                // The server stores these as sent, so PUT must return the ETag.
                $this->assertNotNull($etag, "$kind $step");
                $this->assertSame($get->headers->get('ETag'), $etag, "$kind $step");
            }

            $current = $this->dav('GET', $url)->headers->get('ETag');
            $this->dav('PUT', $url, ['CONTENT_TYPE' => $type, 'HTTP_IF_MATCH' => (string) $current], $body)->assertNoContent();
            $this->dav('PUT', $url, ['CONTENT_TYPE' => $type, 'HTTP_IF_MATCH' => (string) $current], $body)->assertStatus(412);
        }
    }

    /**
     * sabre adds a UID to a vCard that has none, so the stored card differs
     * from the one sent: no ETag, and the client fetches it.
     */
    public function test_no_etag_is_returned_when_the_server_changed_the_data(): void
    {
        $put = $this->dav('PUT', '/dav/addressbooks/ada/default/no-uid.vcf', ['CONTENT_TYPE' => 'text/vcard'],
            "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:No UID\r\nN:UID;No;;;\r\nEND:VCARD\r\n");

        $put->assertCreated();
        $this->assertNull($put->headers->get('ETag'));
        $this->assertStringContainsString('UID:', $this->body($this->dav('GET', '/dav/addressbooks/ada/default/no-uid.vcf')->assertOk()));
    }

    private function calendar(string $component, string $lines): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//NexusDAV//test//EN\r\nBEGIN:$component\r\nUID:".strtolower($component)."-1\r\nDTSTAMP:20260101T000000Z\r\nSUMMARY:Ada\r\n$lines"."END:$component\r\nEND:VCALENDAR\r\n";
    }

    /**
     * @param  array<string, string>  $server
     */
    private function dav(string $method, string $url, array $server = [], ?string $body = null): TestResponse
    {
        $server['HTTP_AUTHORIZATION'] = 'Basic '.base64_encode('ada:'.$this->password);

        return $this->call($method, $url, [], [], [], $server, $body);
    }

    private function body(TestResponse $response): string
    {
        return $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : (string) $response->getContent();
    }
}
