<?php

namespace App\Portal;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Per-user UI state of the portal (hidden collections, the last calendar
 * view, ...) in users.portal_state, one JSON object, so an app adds a key
 * without a migration. Settings with a meaning get their own column. Keys
 * are named by the app, such as "calendar.view".
 */
final class PortalState
{
    public const MAX_BYTES = 65536;

    private const KEY_PATTERN = '/^[a-z0-9_-]+(\.[a-z0-9_-]+)*$/';

    public function get(User $user, string $key, mixed $default = null): mixed
    {
        $this->assertKey($key);

        return $this->all($user)[$key] ?? $default;
    }

    public function put(User $user, string $key, mixed $value): void
    {
        $this->change($user, $key, function (array &$state) use ($key, $value): void {
            $state[$key] = $value;
        });
    }

    public function forget(User $user, string $key): void
    {
        $this->change($user, $key, function (array &$state) use ($key): void {
            unset($state[$key]);
        });
    }

    /**
     * Two requests of one user each hold the User loaded when that request
     * was authenticated, and sessions do not queue them. Read the column
     * again under the row lock so the later write keeps the earlier key.
     * SQLite ignores lockForUpdate(); its IMMEDIATE transaction is the lock.
     * PostgreSQL locks the row, and the second request waits.
     *
     * @param  Closure(array<string, mixed>): void  $apply
     */
    private function change(User $user, string $key, Closure $apply): void
    {
        $this->assertKey($key);

        DB::transaction(function () use ($user, $apply): void {
            $state = $this->locked($user);
            $before = $state;
            $apply($state);

            if ($state === $before) {
                $user->setAttribute('portal_state', $state);
                $user->syncOriginalAttribute('portal_state');

                return;
            }

            $this->write($user, $state);
        });
    }

    /**
     * The document as stored now. The query builder does not apply the
     * model's array cast, so the column comes back as JSON text.
     *
     * @return array<string, mixed>
     */
    private function locked(User $user): array
    {
        $raw = User::query()->whereKey($user->getKey())->lockForUpdate()->value('portal_state');

        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function all(User $user): array
    {
        $state = $user->portal_state;

        return is_array($state) ? $state : [];
    }

    /**
     * Only this column is written, so the user's other fields and the save
     * hooks of the account (principal, admin checks) are left alone.
     *
     * @param  array<string, mixed>  $state
     */
    private function write(User $user, array $state): void
    {
        $json = json_encode($state === [] ? (object) [] : $state, JSON_THROW_ON_ERROR);

        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('The portal state is larger than 64 KB.');
        }

        User::query()->whereKey($user->getKey())->update(['portal_state' => $json]);

        $user->setAttribute('portal_state', $state);
        $user->syncOriginalAttribute('portal_state');
    }

    private function assertKey(string $key): void
    {
        if (strlen($key) > 64 || preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException('A portal state key is lower case words joined by dots.');
        }
    }
}
