<?php

namespace App\Services\Push;

use App\Jobs\SendPushMessages;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The merge window (docs/design/webdav-push.md, "Merge window"): the first
 * change to a topic writes a push_outbox row and queues one job about five
 * seconds later; changes before the job runs update that row. The job
 * takes the row, so a change after that starts a new window. The row and
 * its job are written in one transaction, so neither is left without the
 * other.
 */
final class PushQueue
{
    public const WINDOW_SECONDS = 5;

    /**
     * Never throws: it runs after the change has committed, and a failure
     * here must not turn a saved write into an error for the client.
     *
     * @param  list<string>  $skip
     */
    public function add(string $topic, ?int $contentDepth, bool $property, ?string $source, array $skip): void
    {
        try {
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                if (DB::transaction(fn (): bool => $this->write($topic, $contentDepth, $property, $source, $skip))) {
                    return;
                }
            }
        } catch (Throwable $exception) {
            PushConfig::log('warning', 'A push change could not be queued', ['exception' => $exception]);
        }
    }

    /**
     * @param  list<string>  $skip
     */
    private function write(string $topic, ?int $contentDepth, bool $property, ?string $source, array $skip): bool
    {
        $row = DB::table('push_outbox')->where('topic', $topic)->lockForUpdate()->first();
        $now = now();

        if ($row === null) {
            $inserted = DB::table('push_outbox')->insertOrIgnore([
                'topic' => $topic,
                'source' => $source,
                'content_depth' => $contentDepth,
                'property_update' => $property,
                'skip' => json_encode($skip, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted === 0) {
                // Another change opened the window just now: merge into it.
                return false;
            }

            SendPushMessages::dispatch($topic)->delay($now->copy()->addSeconds(self::WINDOW_SECONDS));

            return true;
        }

        $depths = array_filter([$row->content_depth, $contentDepth], static fn (mixed $depth): bool => $depth !== null);
        $stored = json_decode((string) $row->skip, true);

        // A registration is skipped only if every change in the window
        // named it.
        $kept = array_values(array_intersect(is_array($stored) ? $stored : [], $skip));

        DB::table('push_outbox')->where('id', $row->id)->update([
            'source' => $source ?? $row->source,
            'content_depth' => $depths === [] ? null : min(array_map('intval', $depths)),
            'property_update' => (bool) $row->property_update || $property,
            'skip' => json_encode($kept, JSON_THROW_ON_ERROR),
            'updated_at' => $now,
        ]);

        return true;
    }
}
