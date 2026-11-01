<?php

namespace App\Services;

use App\Models\DiagnosticEvent;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Counts behind the Dashboard's "Needs attention" card and the Diagnostics
 * page, so the two always agree. Each row counts its repeat_count.
 */
final class DiagnosticsSummary
{
    public const WINDOW_HOURS = 24;

    public function since(): CarbonInterface
    {
        return now()->subHours(self::WINDOW_HOURS);
    }

    /**
     * Rejected logins on the admin panel and on DAV.
     */
    public function failedLogins(?CarbonInterface $since = null): int
    {
        return $this->sum(self::failedLoginsQuery(), $since);
    }

    /**
     * 4xx and 5xx answers on /dav, including rejected DAV logins.
     */
    public function davErrors(?CarbonInterface $since = null): int
    {
        return $this->sum(self::davErrorsQuery(), $since);
    }

    /**
     * @return Builder<DiagnosticEvent>
     */
    public static function failedLoginsQuery(): Builder
    {
        return DiagnosticEvent::query()->where('kind', DiagnosticEvent::KIND_AUTH_FAILED);
    }

    /**
     * @return Builder<DiagnosticEvent>
     */
    public static function davErrorsQuery(): Builder
    {
        return DiagnosticEvent::query()
            ->where('source', DiagnosticEvent::SOURCE_DAV)
            ->whereIn('kind', [DiagnosticEvent::KIND_AUTH_FAILED, DiagnosticEvent::KIND_HTTP_ERROR]);
    }

    /**
     * @param  Builder<DiagnosticEvent>  $query
     */
    private function sum(Builder $query, ?CarbonInterface $since): int
    {
        return (int) $query->where('occurred_at', '>=', $since ?? $this->since())->sum('repeat_count');
    }
}
