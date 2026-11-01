<?php

namespace App\Services;

use App\Http\Middleware\RequestLog;
use App\Models\DiagnosticEvent;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stores a rejected login (and writes one log line) or a DAV error answer.
 * The password, token secret, and request body are never accepted here.
 */
final class DiagnosticEventWriter
{
    public function __construct(private AuthThrottle $throttle) {}

    public function recordAdmin(Failed $event): void
    {
        $typed = '';

        if (isset($event->credentials['email']) && is_string($event->credentials['email'])) {
            $typed = $event->credentials['email'];
        }

        $user = $event->user instanceof User ? $event->user : null;
        $branch = request()->attributes->get('nexusdav.admin_auth_failure');

        if (! $user instanceof User) {
            $username = $typed;
            $reason = 'unknown user';
        } elseif ($branch === 'panel') {
            $username = $user->username;
            $reason = 'panel refused';
        } else {
            $username = $user->username;
            $reason = 'bad password';
        }

        $panel = Filament::getPanel('admin');
        $path = '/'.trim((string) $panel->getPath(), '/').$panel->getLoginRouteSlug();

        $this->recordAuthFailure(
            DiagnosticEvent::SOURCE_ADMIN,
            $this->throttle->clientIp(),
            $username,
            null,
            request()->getMethod(),
            $path,
            422,
            $reason,
        );
    }

    public function recordAuthFailure(
        string $source,
        string $ip,
        string $username,
        ?string $publicId,
        string $method,
        string $path,
        int $status,
        string $reason,
    ): void {
        $stored = $this->store(
            DiagnosticEvent::KIND_AUTH_FAILED,
            $source,
            $ip,
            $username,
            $publicId,
            $method,
            $path,
            $status,
            $reason,
            DiagnosticEvent::REASON_MANY_USERNAMES,
        );

        if ($stored === null) {
            return;
        }

        Log::warning('Authentication failed', [
            'source' => $stored['source'],
            'ip' => $stored['ip'],
            'username' => $stored['username'],
            'path' => $stored['path'],
            'status' => $status,
            'reason' => $stored['reason'],
        ]);
    }

    /**
     * A 4xx or 5xx answer on /dav, other than 401 (recordAuthFailure keeps
     * those with a reason). Method, path, status and user only: no body, no
     * query string, no credentials. Not logged; the rows are on Diagnostics.
     */
    public function recordHttpError(string $ip, string $username, string $method, string $path, int $status): void
    {
        $this->store(
            DiagnosticEvent::KIND_HTTP_ERROR,
            DiagnosticEvent::SOURCE_DAV,
            $ip,
            $username,
            null,
            $method,
            $path,
            $status,
            Response::$statusTexts[$status] ?? 'HTTP error',
            DiagnosticEvent::REASON_MANY_REQUESTS,
        );
    }

    /**
     * Inserts or counts one row. One address gets a bounded number of rows of
     * each kind per window; past that, its events are counted on one summary
     * row. Returns the stored key, or null when the event only added to an
     * existing summary row.
     *
     * @return array{source: string, kind: string, ip: string, username: string, path: string, reason: string, bucket: int}|null
     */
    private function store(
        string $kind,
        string $source,
        string $ip,
        string $username,
        ?string $publicId,
        string $method,
        string $path,
        int $status,
        string $reason,
        string $summaryReason,
    ): ?array {
        $this->pruneExpired();

        $source = $this->clip($source, 16);
        $ip = $this->clip($ip, 45);
        $username = $this->clip($username, 255);
        $method = $this->clip(strtoupper($method), 16);
        $path = $this->clip($path, 512);
        $reason = $this->clip($reason, 64);
        $publicId = is_string($publicId) && $publicId !== '' ? $this->clip($publicId, 32) : null;
        $requestId = RequestLog::id();

        $collapse = max(1, (int) config('nexusdav.diagnostics.collapse_seconds', 60));

        // Epoch buckets, not a sliding minute. Two events less than collapse_seconds
        // apart can be two rows when they cross a boundary.
        $bucket = intdiv(now()->getTimestamp(), $collapse);

        $key = [
            'source' => $source,
            'kind' => $kind,
            'ip' => $ip,
            'username' => $username,
            'path' => $path,
            'reason' => $reason,
            'bucket' => $bucket,
        ];

        if (! DiagnosticEvent::query()->where($key)->exists() && $this->addressIsFull($ip, $bucket, $kind, $summaryReason)) {
            $key['username'] = '*';
            $key['path'] = '*';
            $key['reason'] = $summaryReason;
            $publicId = null;
        }

        $inserted = DiagnosticEvent::query()->insertOrIgnore([
            ...$key,
            'public_id' => $publicId,
            'request_id' => $requestId === null ? null : $this->clip($requestId, 64),
            'method' => $method,
            'status' => $status,
            'repeat_count' => 1,
            'occurred_at' => now(),
        ]);

        if ($inserted === 0) {
            DiagnosticEvent::query()->where($key)->increment('repeat_count');
        }

        if ($inserted === 0 && $key['reason'] === $summaryReason) {
            return null;
        }

        return $key;
    }

    private function addressIsFull(string $ip, int $bucket, string $kind, string $summaryReason): bool
    {
        $limit = max(1, (int) config('nexusdav.diagnostics.max_rows_per_address', 10));

        return DiagnosticEvent::query()
            ->where('ip', $ip)
            ->where('kind', $kind)
            ->where('bucket', $bucket)
            ->where('reason', '!=', $summaryReason)
            ->count() >= $limit;
    }

    private function pruneExpired(): void
    {
        $interval = (int) config('nexusdav.diagnostics.prune_interval_seconds', 3600);

        if (! Cache::add('nexusdav.diagnostic_events.prune', 1, $interval)) {
            return;
        }

        $days = (int) config('nexusdav.diagnostics.retention_days', 30);

        DiagnosticEvent::query()
            ->where('occurred_at', '<', now()->subDays($days))
            ->delete();
    }

    private function clip(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit);
    }
}
