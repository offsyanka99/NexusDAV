<?php

namespace App\Services\Portal\Index;

use App\Services\Dav\AddressBookBackend;
use App\Services\Dav\CalendarBackend;
use App\Services\Dav\DavWriteLock;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Brings item_index in step with the DAV rows (nexusdav:index-items): it
 * indexes objects without a row, rebuilds rows of an older indexer version
 * or of another ETag, and removes rows whose object is gone. Keyset paging,
 * one collection and CHUNK objects at a time.
 *
 * The race rule: objects are read through the backend and parsed outside
 * any lock; then, in a transaction holding the collection's lock, their
 * current ETags are read again and only rows whose object is unchanged are
 * written. A write that landed in between keeps the newer row its own hook
 * wrote. Idempotent.
 */
final class IndexBackfill
{
    public const CHUNK = 500;

    /**
     * Called after a chunk is parsed and before its rows are written (tests).
     */
    private ?Closure $beforeWrite = null;

    public function __construct(private ItemIndexer $indexer) {}

    public function beforeWrite(?Closure $callback): void
    {
        $this->beforeWrite = $callback;
    }

    /**
     * @param  (Closure(string): void)|null  $progress
     * @return array{indexed: int, failed: int, changed: int, removed: int}
     */
    public function run(?Closure $progress = null): array
    {
        $counts = ['indexed' => 0, 'failed' => 0, 'changed' => 0, 'removed' => 0];

        foreach (DB::table('calendars')->orderBy('id')->pluck('id') as $id) {
            $this->collection(true, (int) $id, $counts);
        }

        $progress?->__invoke('Calendars: '.$counts['indexed'].' indexed, '.$counts['failed'].' not readable.');

        foreach (DB::table('addressbooks')->orderBy('id')->pluck('id') as $id) {
            $this->collection(false, (int) $id, $counts);
        }

        $counts['removed'] = $this->removeOrphans();
        $progress?->__invoke('Address books done; '.$counts['removed'].' rows of deleted items removed.');

        return $counts;
    }

    /**
     * @param  array{indexed: int, failed: int, changed: int, removed: int}  $counts
     */
    private function collection(bool $calendar, int $id, array &$counts): void
    {
        $objects = $calendar ? 'calendarobjects' : 'cards';
        $foreign = $calendar ? 'calendarid' : 'addressbookid';
        $column = $calendar ? 'calendar_id' : 'addressbook_id';
        $last = 0;

        do {
            $page = DB::table($objects.' as o')
                ->leftJoin('item_index as i', function (JoinClause $join) use ($column, $foreign): void {
                    $join->on('i.'.$column, '=', 'o.'.$foreign)->on('i.uri', '=', 'o.uri');
                })
                ->where('o.'.$foreign, $id)
                ->where('o.id', '>', $last)
                ->where(function (Builder $query): void {
                    $query->whereNull('i.id')
                        ->orWhere('i.indexer_version', '<', ItemIndexer::VERSION)
                        ->orWhereColumn('i.etag', '!=', 'o.etag');
                })
                ->orderBy('o.id')
                ->limit(self::CHUNK)
                ->get(['o.id', 'o.uri']);

            if ($page->isEmpty()) {
                return;
            }

            $last = (int) $page->last()->id;
            $uris = $page->pluck('uri')->map(static fn (mixed $uri): string => (string) $uri)->all();
            $rows = [];

            foreach ($this->read($calendar, $id, $uris) as $object) {
                $row = $this->indexer->row($calendar, $id, $object['uri'], $object['data'], $object['etag']);
                $rows[$object['uri']] = $row;
            }

            ($this->beforeWrite)?->__invoke($calendar, $id, $uris);

            DB::transaction(function () use ($calendar, $id, $objects, $foreign, $uris, $rows, &$counts): void {
                if ($calendar) {
                    DavWriteLock::lockCalendar($id);
                } else {
                    DavWriteLock::lockAddressBook($id);
                }

                $current = DB::table($objects)->where($foreign, $id)->whereIn('uri', $uris)->pluck('etag', 'uri');
                $write = [];

                foreach ($rows as $uri => $row) {
                    if ((string) ($current[$uri] ?? '') !== $row['etag']) {
                        $counts['changed']++;

                        continue;
                    }

                    $write[] = $row;
                }

                try {
                    DB::transaction(fn () => $this->indexer->write($write));
                    $versions = array_column($write, 'indexer_version');
                } catch (Throwable) {
                    // One row the database refuses must not stop the run.
                    $versions = array_map(fn (array $row): ?int => $this->indexer->writeSafely($row), $write);
                }

                foreach ($versions as $version) {
                    $version === ItemIndexer::VERSION ? $counts['indexed']++ : $counts['failed']++;
                }
            });
        } while ($page->count() === self::CHUNK);
    }

    /**
     * The objects as the backend reads them: PostgreSQL keeps their text as
     * bytea, which only the backend turns back into the text.
     *
     * @param  list<string>  $uris
     * @return list<array{uri: string, data: string, etag: string}>
     */
    private function read(bool $calendar, int $id, array $uris): array
    {
        $rows = $calendar
            ? app(CalendarBackend::class)->getMultipleCalendarObjects([$id, 0], $uris)
            : app(AddressBookBackend::class)->getMultipleCards($id, $uris);
        $objects = [];

        foreach ($rows as $row) {
            $objects[] = [
                'uri' => (string) $row['uri'],
                'data' => (string) ($row[$calendar ? 'calendardata' : 'carddata'] ?? ''),
                'etag' => trim((string) ($row['etag'] ?? ''), '"'),
            ];
        }

        return $objects;
    }

    /**
     * Rows whose object was removed without the backend (a direct delete).
     */
    private function removeOrphans(): int
    {
        $removed = 0;

        foreach ([['calendar_id', 'calendarobjects', 'calendarid'], ['addressbook_id', 'cards', 'addressbookid']] as [$column, $objects, $foreign]) {
            $removed += DB::table('item_index')
                ->whereNotNull($column)
                ->whereNotExists(function (Builder $query) use ($column, $objects, $foreign): void {
                    $query->selectRaw('1')
                        ->from($objects)
                        ->whereColumn($objects.'.'.$foreign, 'item_index.'.$column)
                        ->whereColumn($objects.'.uri', 'item_index.uri');
                })
                ->delete();
        }

        return $removed;
    }
}
