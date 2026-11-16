<?php

namespace Tests\Feature;

use App\Services\Portal\CollectionRef;
use App\Services\Portal\Exceptions\AlreadyExists;
use App\Services\Portal\Exceptions\ChangedElsewhere;
use App\Services\Portal\Exceptions\InvalidItem;
use App\Services\Portal\Exceptions\LockedItem;
use App\Services\Portal\Exceptions\NotFound;
use App\Services\Portal\Exceptions\PortalException;
use App\Services\Portal\Exceptions\ReadOnlyCollection;
use App\Services\Portal\PortalErrors;
use Filament\Notifications\Notification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * PR 50a, step 1: every refusal of a store or reader has a text in English
 * and Russian, and PortalErrors turns it into a notification.
 */
class PortalErrorsTest extends TestCase
{
    /**
     * @return list<PortalException>
     */
    private function refusals(): array
    {
        return [
            new NotFound,
            new ReadOnlyCollection(true, [CollectionRef::REASON_READ_ONLY_SHARE]),
            new ReadOnlyCollection(true, [CollectionRef::REASON_HOLIDAY]),
            new ReadOnlyCollection(false, [CollectionRef::REASON_READ_ONLY_SHARE]),
            new ChangedElsewhere,
            new InvalidItem('This calendar does not take tasks.'),
            new LockedItem,
            new AlreadyExists,
            new AlreadyExists(true),
            AlreadyExists::uid(),
        ];
    }

    public function test_every_refusal_has_an_english_and_a_russian_text(): void
    {
        $english = json_decode(File::get(lang_path('en.json')), true, flags: JSON_THROW_ON_ERROR);
        $texts = [];

        foreach ($this->refusals() as $refusal) {
            App::setLocale('en');
            $title = $refusal->title();
            $body = $refusal->body();

            $this->assertArrayHasKey($title, $english, $refusal::class);

            if ($body !== null) {
                $this->assertArrayHasKey($body, $english, $refusal::class);
            }

            App::setLocale('ru');
            $this->assertNotSame($title, $refusal->title(), $refusal::class);
            $this->assertMatchesRegularExpression('/\p{Cyrillic}/u', $refusal->title());

            if ($body !== null) {
                $this->assertNotSame($body, $refusal->body(), $refusal::class);
            }

            $texts[] = $title;
        }

        App::setLocale('en');
        $this->assertContains('This item was changed on another device. Reload to see the new version.', $texts);
        $this->assertContains('This calendar is read-only.', $texts);
        $this->assertContains('Another app is editing this file.', $texts);
    }

    public function test_a_refusal_becomes_a_danger_notification_and_anything_else_is_thrown_again(): void
    {
        $notification = PortalErrors::notification(new InvalidItem('Validation error in iCalendar: DTSTART is wrong'));

        $this->assertSame('danger', $notification->getStatus());
        $this->assertSame('This could not be saved.', $notification->getTitle());
        $this->assertSame('Validation error in iCalendar: DTSTART is wrong', $notification->getBody());

        PortalErrors::send(new ChangedElsewhere);
        Notification::assertNotified(Notification::make()->danger()->title('This item was changed on another device. Reload to see the new version.'));

        $timeout = new PDOException('canceling statement due to lock timeout');
        (new \ReflectionProperty(PDOException::class, 'code'))->setValue($timeout, '55P03');
        $busy = new QueryException('pgsql', 'SELECT pg_advisory_xact_lock(1, 1)', [], $timeout);
        $this->assertTrue(PortalErrors::handles($busy));
        $this->assertSame('The server is busy. Try again in a moment.', PortalErrors::notification($busy)->getTitle());

        $this->expectException(RuntimeException::class);
        PortalErrors::send(new RuntimeException('A bug.'));
    }
}
