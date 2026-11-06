<?php

namespace App\Services\Dav;

use App\Models\Principal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * sabre rows point at a principal by URI, so the users foreign key cannot
 * reach them. Delete them with the user: their collections with every share
 * of them, and the shares they received. Home paths use the username, and
 * underscores in a username are escaped so a neighbour is not removed.
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

        $ownedCalendarIds = DB::table('calendarinstances')
            ->where('principaluri', $uri)
            ->where('access', 1)
            ->pluck('calendarid');

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

        foreach (['principals/'.$user->username, 'calendars/'.$user->username, 'addressbooks/'.$user->username] as $path) {
            $this->deletePath($path);
        }
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
