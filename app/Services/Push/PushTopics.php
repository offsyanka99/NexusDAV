<?php

namespace App\Services\Push;

use App\Services\Dav\AddressBookHome;
use App\Services\Dav\CalendarHome;
use App\Services\Dav\FileDirectory;
use App\Services\Dav\FileHome;
use Illuminate\Support\Facades\DB;
use Normalizer;
use Sabre\CalDAV\Calendar;
use Sabre\CardDAV\AddressBook;
use Sabre\DAV\INode;

/**
 * Push topics and which resources offer push.
 *
 * A topic names a collection, not a URL: base64url(HMAC-SHA256(topic key,
 * "<kind>:<id>")), cut to 22 characters. A shared calendar or address book
 * has its owner's id, so every sharee's path has the same topic. The topic
 * shows neither the user nor the collection name.
 */
final class PushTopics
{
    /**
     * Depth names in order, so a smaller index is a shallower depth.
     */
    public const DEPTHS = ['0', '1', 'infinity'];

    public function __construct(private PushKeys $keys) {}

    public function calendar(int $calendarId): string
    {
        return $this->topic('calendar', (string) $calendarId);
    }

    public function addressBook(int $addressBookId): string
    {
        return $this->topic('addressbook', (string) $addressBookId);
    }

    public function home(bool $calendar, string $principalUri): string
    {
        return $this->topic($calendar ? 'calendar-home' : 'addressbook-home', $principalUri);
    }

    /**
     * A folder in a file home, or the file home itself, by its DAV path.
     */
    public function files(string $path): string
    {
        return $this->topic('files', self::filesPath($path));
    }

    /**
     * The capability of the node at this DAV path, or null when it offers
     * no push. File folders offer it only with Include Files in push on.
     */
    public function capability(string $path, INode $node): ?PushCapability
    {
        $path = trim($path, '/');
        $segments = explode('/', $path);

        if (count($segments) === 2 && ($node instanceof CalendarHome || $node instanceof AddressBookHome)) {
            return new PushCapability($path, $this->home($node instanceof CalendarHome, 'principals/'.$segments[1]), '1', null);
        }

        if (count($segments) === 3 && $segments[0] === 'calendars' && $node instanceof Calendar) {
            $id = DB::table('calendarinstances')
                ->where('principaluri', 'principals/'.$segments[1])
                ->where('uri', $segments[2])
                ->value('calendarid');

            return $id === null ? null : new PushCapability($path, $this->calendar((int) $id), '1', '0', 'calendar:'.(int) $id);
        }

        if (count($segments) === 3 && $segments[0] === 'addressbooks' && $node instanceof AddressBook) {
            $id = self::addressBookId('principals/'.$segments[1], $segments[2]);

            return $id === null ? null : new PushCapability($path, $this->addressBook($id), '1', '0', 'addressbook:'.$id);
        }

        if ($segments[0] === 'files' && $node instanceof FileDirectory && PushConfig::files()) {
            $path = self::filesPath($path);

            return new PushCapability($path, $this->files($path), $node instanceof FileHome ? 'infinity' : '1', null);
        }

        return null;
    }

    /**
     * The owner's book under that name, else a share the principal received.
     */
    public static function addressBookId(string $principalUri, string $uri): ?int
    {
        $id = DB::table('addressbooks')->where('principaluri', $principalUri)->where('uri', $uri)->value('id')
            ?? DB::table('addressbookinstances')->where('principaluri', $principalUri)->where('uri', $uri)->value('addressbookid');

        return $id === null ? null : (int) $id;
    }

    /**
     * Folder names are stored in Unicode NFC, and a client may send another
     * form of the same name.
     */
    public static function filesPath(string $path): string
    {
        $path = trim($path, '/');
        $normal = Normalizer::normalize($path, Normalizer::FORM_C);

        return is_string($normal) ? $normal : $path;
    }

    /**
     * True when the first depth reaches at least as deep as the second.
     */
    public static function covers(string $granted, string $needed): bool
    {
        return array_search($granted, self::DEPTHS, true) >= array_search($needed, self::DEPTHS, true);
    }

    private function topic(string $kind, string $id): string
    {
        $mac = hash_hmac('sha256', $kind.':'.$id, $this->keys->topicKey(), true);

        return substr(rtrim(strtr(base64_encode($mac), '+/', '-_'), '='), 0, 22);
    }
}
