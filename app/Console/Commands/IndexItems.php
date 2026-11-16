<?php

namespace App\Console\Commands;

use App\Services\Portal\Index\IndexBackfill;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Brings the portal's item index in step with the DAV rows. Run by
 * nexusdav:prepare at every start; safe to run again at any time.
 */
class IndexItems extends Command
{
    protected $signature = 'nexusdav:index-items';

    protected $description = 'Index calendar objects and contacts for the user portal';

    public function handle(IndexBackfill $backfill): int
    {
        $started = microtime(true);
        $counts = $backfill->run(fn (string $line) => $this->line($line));
        $seconds = round(microtime(true) - $started, 1);

        $this->info(sprintf(
            'Item index: %d indexed, %d not readable (kept by name), %d changed during the run, %d removed, in %s s.',
            $counts['indexed'], $counts['failed'], $counts['changed'], $counts['removed'], $seconds,
        ));

        if ($counts['indexed'] + $counts['failed'] + $counts['removed'] > 0) {
            Log::info('Item index updated', [...$counts, 'seconds' => $seconds]);
        }

        return self::SUCCESS;
    }
}
