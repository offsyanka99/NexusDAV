<?php

namespace App\Services\Portal\Index;

use App\Models\ItemIndex;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Reader;
use Throwable;
use UnexpectedValueException;

/**
 * Keeps item_index in step with the DAV rows (Decision 9). The calendar and
 * address book backends call it on every create, update and delete of an
 * object, inside the write's own transaction and lock, so DAV clients, the
 * portal's stores, holiday calendars and import all keep it current.
 *
 * An object it cannot parse never fails the write: the row is kept with
 * indexer version 0 (found by its URI, retried by nexusdav:index-items) and
 * the problem is logged without the object's content. The index is written
 * in a savepoint, so a failed statement does not abort the write's
 * transaction on PostgreSQL.
 */
final class ItemIndexer
{
    /**
     * Raise it when the fields change: nexusdav:index-items rebuilds every
     * row with a lower version.
     */
    public const VERSION = 1;

    private const COMPONENTS = ['VEVENT' => EventIndexer::class, 'VTODO' => TaskIndexer::class, 'VJOURNAL' => NoteIndexer::class];

    private const COLUMNS = [
        'kind', 'uid', 'title', 'sort_name', 'organization', 'email', 'phone', 'starts_at', 'ends_at', 'all_day', 'floating',
        'due_at', 'completed_at', 'status', 'parent_uid', 'recurring', 'search_text', 'etag', 'indexer_version',
    ];

    public function indexCalendarObject(int $calendarId, string $uri, string $data, string $etag): void
    {
        $this->writeSafely($this->row(true, $calendarId, $uri, $data, $etag));
    }

    public function indexCard(int $addressBookId, string $uri, string $data, string $etag): void
    {
        $this->writeSafely($this->row(false, $addressBookId, $uri, $data, $etag));
    }

    /**
     * Writes one row in a savepoint. When the database refuses a value, the
     * version-0 row (found by its URI, retried later) is written instead, so
     * the item is never missing. Returns the version written, or null when
     * not even that could be written (logged).
     *
     * @param  array<string, mixed>  $row
     */
    public function writeSafely(array $row): ?int
    {
        $type = $row['calendar_id'] !== null ? 'calendar' : 'addressbook';
        $collection = (int) ($row['calendar_id'] ?? $row['addressbook_id']);

        foreach ($row['indexer_version'] === 0 ? [$row] : [$row, $this->minimal($row)] as $attempt) {
            try {
                DB::transaction(fn () => $this->write([$attempt]));

                return (int) $attempt['indexer_version'];
            } catch (Throwable $exception) {
                Log::error('The item index could not be updated', [$type => $collection, 'error' => $exception::class]);
            }
        }

        return null;
    }

    /**
     * The row of an object the index cannot hold: kind, URI and ETag only.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function minimal(array $row): array
    {
        $minimal = $row;

        foreach (self::COLUMNS as $column) {
            if (! in_array($column, ['kind', 'etag', 'indexer_version'], true)) {
                $minimal[$column] = in_array($column, ['all_day', 'floating', 'recurring'], true) ? false : null;
            }
        }

        $minimal['indexer_version'] = 0;

        return $minimal;
    }

    public function forgetCalendarObject(int $calendarId, string $uri): void
    {
        $this->safely('calendar', $calendarId, fn () => DB::table('item_index')->where('calendar_id', $calendarId)->where('uri', $uri)->delete());
    }

    public function forgetCard(int $addressBookId, string $uri): void
    {
        $this->safely('addressbook', $addressBookId, fn () => DB::table('item_index')->where('addressbook_id', $addressBookId)->where('uri', $uri)->delete());
    }

    /**
     * The item_index row of one object: its fields, or a version-0 row when
     * the object cannot be parsed.
     *
     * @return array<string, mixed>
     */
    public function row(bool $calendar, int $collectionId, string $uri, string $data, string $etag): array
    {
        try {
            $fields = $this->fields($data);
            $version = self::VERSION;
        } catch (Throwable $exception) {
            Log::warning('An item could not be indexed', [
                $calendar ? 'calendar' : 'addressbook' => $collectionId,
                'error' => $exception::class,
            ]);

            $fields = ['kind' => $calendar ? $this->guessKind($data) : ItemIndex::CONTACT];
            $version = 0;
        }

        $row = [
            'calendar_id' => $calendar ? $collectionId : null,
            'addressbook_id' => $calendar ? null : $collectionId,
            'uri' => $uri,
            'etag' => trim($etag, '"'),
            'indexer_version' => $version,
        ];

        foreach (self::COLUMNS as $column) {
            if (! array_key_exists($column, $row)) {
                $row[$column] = $fields[$column] ?? match ($column) {
                    'all_day', 'floating', 'recurring' => false,
                    default => null,
                };
            }
        }

        return $row;
    }

    /**
     * The indexed fields of an iCalendar object or a vCard. Throws when the
     * text is neither, or has no event, task or note.
     *
     * @return array<string, mixed>
     */
    public function fields(string $data): array
    {
        $document = Reader::read($data, Reader::OPTION_FORGIVING);

        try {
            if ($document instanceof VCard) {
                return ['uid' => IndexValues::text(IndexValues::first($document, 'UID'), 200), ...(new ContactIndexer)->fields($document, false), 'kind' => ItemIndex::CONTACT];
            }

            if (! $document instanceof VCalendar) {
                throw new UnexpectedValueException('Not an iCalendar object or a vCard.');
            }

            [$name, $master, $recurring] = $this->master($document);
            $indexer = new (self::COMPONENTS[$name]);

            return ['uid' => IndexValues::text(IndexValues::first($master, 'UID'), 200), ...$indexer->fields($master, $recurring), 'kind' => $indexer->kind()];
        } finally {
            $document->destroy();
        }
    }

    /**
     * Writes rows of one kind of collection (all calendar rows or all
     * address book rows), replacing the row of the same collection and URI.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function write(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $calendar = $rows[0]['calendar_id'] !== null;

        DB::table('item_index')->upsert($rows, $calendar ? ['calendar_id', 'uri'] : ['addressbook_id', 'uri'], self::COLUMNS);
    }

    /**
     * The kind is the first component that is not a time zone; the indexed
     * component is the master (without RECURRENCE-ID), or the first one
     * when the object holds only overrides.
     *
     * @return array{0: string, 1: Component, 2: bool}
     */
    private function master(VCalendar $calendar): array
    {
        $name = null;

        foreach ($calendar->getComponents() as $component) {
            if ($component->name !== 'VTIMEZONE') {
                $name = $component->name;

                break;
            }
        }

        if (! is_string($name) || ! isset(self::COMPONENTS[$name])) {
            throw new UnexpectedValueException('No event, task or note.');
        }

        $master = null;
        $overrides = false;

        foreach ($calendar->select($name) as $component) {
            if (! $component instanceof Component) {
                continue;
            }

            if (IndexValues::has($component, 'RECURRENCE-ID')) {
                $overrides = true;
            } elseif ($master === null) {
                $master = $component;
            }
        }

        if ($master === null) {
            $all = $calendar->select($name);
            $master = reset($all);
        }

        if (! $master instanceof Component) {
            throw new UnexpectedValueException('No event, task or note.');
        }

        return [$name, $master, $overrides || IndexValues::has($master, 'RRULE') || IndexValues::has($master, 'RDATE')];
    }

    private function guessKind(string $data): string
    {
        return match (true) {
            str_contains($data, 'BEGIN:VTODO') => ItemIndex::TASK,
            str_contains($data, 'BEGIN:VJOURNAL') => ItemIndex::NOTE,
            default => ItemIndex::EVENT,
        };
    }

    /**
     * Runs one index statement in a savepoint; a failure is logged and the
     * DAV write goes on.
     */
    private function safely(string $type, int $collectionId, Closure $statement): void
    {
        try {
            DB::transaction($statement);
        } catch (Throwable $exception) {
            Log::error('The item index could not be updated', [$type => $collectionId, 'error' => $exception::class]);
        }
    }
}
