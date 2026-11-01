<?php

namespace App\Services;

use App\Models\AppPassword;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The Dashboard's numbers. Cheap COUNT queries, cached for a minute so a
 * reload does not count again. Which cards show depends on the services
 * that are on, and is decided when the card renders, not here.
 */
final class ServerCounts
{
    public const CACHE_KEY = 'nexusdav.dashboard.counts';

    public const CACHE_SECONDS = 60;

    /**
     * @return array{users: int, disabled_users: int, calendars: int, address_books: int, app_passwords: int, events: int, tasks: int, notes: int, contacts: int}
     */
    public function all(): array
    {
        /** @var array{users: int, disabled_users: int, calendars: int, address_books: int, app_passwords: int, events: int, tasks: int, notes: int, contacts: int} */
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => $this->count());
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{users: int, disabled_users: int, calendars: int, address_books: int, app_passwords: int, events: int, tasks: int, notes: int, contacts: int}
     */
    private function count(): array
    {
        $byComponent = DB::table('calendarobjects')
            ->selectRaw('componenttype, count(*) as total')
            ->groupBy('componenttype')
            ->pluck('total', 'componenttype');

        return [
            'users' => User::query()->count(),
            'disabled_users' => User::query()->where('is_active', false)->count(),
            'calendars' => DB::table('calendarinstances')->count(),
            'address_books' => DB::table('addressbooks')->count(),
            'app_passwords' => AppPassword::query()
                ->whereNull('revoked_at')
                ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->count(),
            'events' => (int) ($byComponent['VEVENT'] ?? 0),
            'tasks' => (int) ($byComponent['VTODO'] ?? 0),
            'notes' => (int) ($byComponent['VJOURNAL'] ?? 0),
            'contacts' => DB::table('cards')->count(),
        ];
    }
}
