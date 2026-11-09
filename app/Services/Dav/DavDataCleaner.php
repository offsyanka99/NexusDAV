<?php

namespace App\Services\Dav;

use App\Models\Principal;
use App\Models\User;
use App\Services\Push\PushChanges;
use App\Services\Push\PushSubscriptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * sabre rows point at a principal by URI, so the users foreign key cannot
 * reach them. Delete them with the user: their collections with every share
 * of them, the shares they received, and their file home with its locks and
 * properties. Home paths use the username, and underscores in a username are
 * escaped so a neighbour is not removed.
 */
final class DavDataCleaner
{
    public function deleteForUser(User $user): void
    {
        $principal = $user->principal()->first();
        $uri = $principal instanceof Principal ? $principal->uri : 'principals/'.$user->username;

        // A share being written for this user commits before the reads below,
        // or finds the principal gone and is refused (HomeNamespace).
        HomeNamespace::lock($uri);

        // The user's own push subscriptions go now. Their collections'
        // other subscribers (sharees) get the deletion as a last message.
        app(PushSubscriptions::class)->forgetUser((int) $user->getKey());

        $ownedCalendarIds = DB::table('calendarinstances')
            ->where('principaluri', $uri)
            ->where('access', 1)
            ->pluck('calendarid');

        foreach ($ownedCalendarIds as $calendarId) {
            app(PushChanges::class)->collectionDeleted(true, (int) $calendarId, DB::table('calendarinstances')
                ->where('calendarid', $calendarId)
                ->where('principaluri', '!=', $uri)
                ->pluck('principaluri')
                ->map(static fn (mixed $principal): string => (string) $principal)
                ->all());
        }

        if ($ownedCalendarIds->isNotEmpty()) {
            $this->deleteSharePaths(true, DB::table('calendarinstances')
                ->whereIn('calendarid', $ownedCalendarIds)
                ->where('principaluri', '!=', $uri)
                ->get(['principaluri', 'uri']));

            DB::table('calendarobjects')->whereIn('calendarid', $ownedCalendarIds)->delete();
            DB::table('calendarchanges')->whereIn('calendarid', $ownedCalendarIds)->delete();
            DB::table('calendarinstances')->whereIn('calendarid', $ownedCalendarIds)->delete();
            DB::table('calendars')->whereIn('id', $ownedCalendarIds)->delete();
        }

        DB::table('calendarinstances')->where('principaluri', $uri)->delete();
        DB::table('calendarsubscriptions')->where('principaluri', $uri)->delete();
        DB::table('schedulingobjects')->where('principaluri', $uri)->delete();

        $addressBookIds = DB::table('addressbooks')->where('principaluri', $uri)->pluck('id');

        foreach ($addressBookIds as $addressBookId) {
            app(PushChanges::class)->collectionDeleted(false, (int) $addressBookId, DB::table('addressbookinstances')
                ->where('addressbookid', $addressBookId)
                ->pluck('principaluri')
                ->map(static fn (mixed $principal): string => (string) $principal)
                ->all());
        }

        // Shares this user received. Their paths are under this user's home,
        // which is cleared below.
        DB::table('addressbookinstances')->where('principaluri', $uri)->delete();

        if ($addressBookIds->isNotEmpty()) {
            $shares = DB::table('addressbookinstances')->whereIn('addressbookid', $addressBookIds);
            $this->deleteSharePaths(false, $shares->clone()->get(['principaluri', 'uri']));
            $shares->delete();

            DB::table('cards')->whereIn('addressbookid', $addressBookIds)->delete();
            DB::table('addressbookchanges')->whereIn('addressbookid', $addressBookIds)->delete();
            DB::table('addressbooks')->whereIn('id', $addressBookIds)->delete();
        }

        foreach (['principals/'.$user->username, 'calendars/'.$user->username, 'addressbooks/'.$user->username, 'files/'.$user->username] as $path) {
            $this->deletePath($path);
        }

        // The file home goes once the deletion is committed, so a rolled
        // back deletion keeps the files. Files that are switched off are
        // deleted too.
        $username = (string) $user->username;
        DB::afterCommit(static function () use ($username): void {
            try {
                app(FileStorage::class)->deleteHome($username);
            } catch (Throwable $exception) {
                Log::error('Could not delete the file home of a deleted user', ['username' => $username, 'exception' => $exception]);
            }
        });
    }

    /**
     * Each sharee kept locks and properties for a share under their own path.
     *
     * @param  iterable<object>  $shares
     */
    private function deleteSharePaths(bool $calendar, iterable $shares): void
    {
        foreach ($shares as $share) {
            $this->deletePath(SharingService::sharePath($calendar, (string) ($share->principaluri ?? ''), (string) ($share->uri ?? '')));
        }
    }

    public function deletePath(string $path): void
    {
        $this->deleteByPath('propertystorage', 'path', $path);
        $this->deleteByPath('locks', 'uri', $path);
        app(PushSubscriptions::class)->forgetPath($path);
    }

    private function deleteByPath(string $table, string $column, string $path): void
    {
        $child = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $path).'/%';

        DB::table($table)->where(function ($query) use ($column, $path, $child): void {
            $query->where($column, $path)
                ->orWhereRaw($column.' LIKE ? ESCAPE ?', [$child, '\\']);
        })->delete();
    }
}
