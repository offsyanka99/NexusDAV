<?php

namespace App\Services\Dav;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drops calendar and address book change rows older than the retention
 * setting. Runs on a DAV write, at most once an hour, one batch per table.
 * The collection's synctoken_min moves forward in the same transaction, and
 * only forward, to one past the newest change number it deleted: every token
 * at or above it still finds all the changes since it.
 *
 * Pruning never fails the client's write; a failure is logged and retried
 * after the interval.
 */
class SyncHistoryPruner
{
    private const CACHE_KEY = 'nexusdav.sync_history.prune';

    public function afterWrite(): void
    {
        $interval = max(1, (int) config('nexusdav.sync_history.prune_interval_seconds', 3600));

        try {
            if (! Cache::add(self::CACHE_KEY, 1, $interval)) {
                return;
            }
        } catch (Throwable $exception) {
            Log::warning('Sync history was not pruned', ['exception' => $exception]);

            return;
        }

        foreach ([['calendarchanges', 'calendars', 'calendarid'], ['addressbookchanges', 'addressbooks', 'addressbookid']] as [$changes, $collections, $foreignKey]) {
            try {
                $this->pruneTable($changes, $collections, $foreignKey);
            } catch (Throwable $exception) {
                Log::warning('Sync history was not pruned', ['table' => $changes, 'exception' => $exception]);
            }
        }
    }

    protected function pruneTable(string $changes, string $collections, string $foreignKey): void
    {
        $days = max(1, (int) config('nexusdav.sync_history.retention_days', 90));
        $batch = max(1, (int) config('nexusdav.sync_history.prune_batch', 1000));
        $cutoff = now()->subDays($days);

        DB::transaction(function () use ($changes, $collections, $foreignKey, $batch, $cutoff): void {
            $rows = DB::table($changes)
                ->where('created_at', '<', $cutoff)
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($batch)
                ->get(['id', $foreignKey, 'synctoken']);

            if ($rows->isEmpty()) {
                return;
            }

            DB::table($changes)->whereIn('id', $rows->pluck('id')->all())->delete();

            foreach ($rows->groupBy($foreignKey) as $collectionId => $deleted) {
                $this->advanceFloor($collections, (int) $collectionId, (int) $deleted->max('synctoken') + 1);
            }
        });
    }

    /**
     * A client token T reads the changes numbered T and up, so the newest
     * deleted number decides. Taking the oldest number still stored instead
     * would accept a token whose change was deleted out of order (created_at
     * is the transaction start on PostgreSQL), and that client would miss it.
     */
    private function advanceFloor(string $collections, int $collectionId, int $floor): void
    {
        DB::table($collections)
            ->where('id', $collectionId)
            ->where('synctoken_min', '<', $floor)
            ->update(['synctoken_min' => $floor]);
    }
}
