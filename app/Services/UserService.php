<?php

namespace App\Services;

use App\Exceptions\LastActiveAdminException;
use App\Models\Principal;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class UserService
{
    /**
     * Set while UserService::create() inserts the user. That insert is followed
     * by its own principal insert, so the saved hook must not insert another.
     */
    private static bool $skipPrincipalSync = false;

    private static int $adminLockDepth = 0;

    public static function skippingPrincipalSync(): bool
    {
        return self::$skipPrincipalSync;
    }

    /**
     * Create the user and their principal together.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): User
    {
        return $this->transaction(function () use ($attributes): User {
            $username = $attributes['username'] ?? null;
            $displayName = $attributes['display_name'] ?? null;
            $email = $attributes['email'] ?? null;

            if (! is_string($username) || ! is_string($displayName) || ! is_string($email)) {
                throw new InvalidArgumentException('Username, display name, and email are required.');
            }

            $displayName = trim($displayName);
            $email = trim($email);

            if (preg_match(User::USERNAME_PATTERN, $username) !== 1) {
                throw new InvalidArgumentException('Username must be lowercase and URL-safe.');
            }

            if ($displayName === '') {
                throw new InvalidArgumentException('Display name is required.');
            }

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('Email must be a valid email address.');
            }

            $isAdmin = $this->flag($attributes['is_admin'] ?? false, false);
            $isActive = array_key_exists('is_active', $attributes)
                ? $this->flag($attributes['is_active'], true)
                : true;
            $password = $this->password($attributes['password'] ?? null);

            if ($isAdmin && $password === null) {
                throw new InvalidArgumentException('An admin must have a password.');
            }

            self::$skipPrincipalSync = true;

            try {
                $user = User::query()->create([
                    'username' => $username,
                    'display_name' => $displayName,
                    'email' => $email,
                    'password' => $password,
                    'is_admin' => $isAdmin,
                    'is_active' => $isActive,
                ]);
            } finally {
                self::$skipPrincipalSync = false;
            }

            $user->setRelation('principal', $this->createPrincipal($user));

            return $user;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $user, array $attributes): User
    {
        return $this->withAdminLock(function () use ($user, $attributes): User {
            return $this->transaction(function () use ($user, $attributes): User {
                unset($attributes['username'], $attributes['password_confirmation']);

                if (array_key_exists('password', $attributes)) {
                    $password = $this->password($attributes['password']);

                    if ($password === null) {
                        unset($attributes['password']);
                    } else {
                        $attributes['password'] = $password;
                    }
                }

                if (array_key_exists('is_admin', $attributes)) {
                    $attributes['is_admin'] = $this->flag($attributes['is_admin'], false);
                }

                if (array_key_exists('is_active', $attributes)) {
                    $attributes['is_active'] = $this->flag($attributes['is_active'], true);
                }

                if (array_key_exists('display_name', $attributes)) {
                    if (! is_string($attributes['display_name']) || trim($attributes['display_name']) === '') {
                        throw new InvalidArgumentException('Display name is required.');
                    }

                    $attributes['display_name'] = trim($attributes['display_name']);
                }

                if (array_key_exists('email', $attributes)) {
                    if (! is_string($attributes['email'])) {
                        throw new InvalidArgumentException('Email must be a valid email address.');
                    }

                    $attributes['email'] = trim($attributes['email']);

                    if (filter_var($attributes['email'], FILTER_VALIDATE_EMAIL) === false) {
                        throw new InvalidArgumentException('Email must be a valid email address.');
                    }
                }

                $user->fill($attributes);

                if ($user->is_admin && ! is_string($user->getAttributes()['password'] ?? null)) {
                    throw new InvalidArgumentException('An admin must have a password.');
                }

                $user->save();

                return $user;
            });
        });
    }

    public function delete(User $user): void
    {
        $this->withAdminLock(function () use ($user): void {
            $this->transaction(function () use ($user): void {
                if ($user->delete() === false) {
                    throw new RuntimeException('The user could not be deleted.');
                }
            });
        });
    }

    /**
     * Create the principal when it is missing, and copy display name and email
     * when it already exists. The user row is left untouched.
     */
    public function syncPrincipal(User $user): Principal
    {
        $principal = Principal::query()->where('user_id', $user->getKey())->first();

        if (! $principal instanceof Principal) {
            $principal = $this->createPrincipal($user);
            $user->setRelation('principal', $principal);

            return $principal;
        }

        if ($principal->display_name !== $user->display_name || $principal->email !== $user->email) {
            $principal->fill([
                'display_name' => $user->display_name,
                'email' => $user->email,
            ])->save();
        }

        $user->setRelation('principal', $principal);

        return $principal;
    }

    public function assertCanSave(User $user): void
    {
        if (! $user->exists) {
            return;
        }

        $this->withAdminLock(function () use ($user): void {
            $staysActiveAdmin = $user->is_admin && $user->is_active;

            if (! $staysActiveAdmin && $this->isLastActiveAdmin($user)) {
                throw LastActiveAdminException::cannotChange();
            }
        });
    }

    public function assertCanDelete(User $user): void
    {
        $this->withAdminLock(function () use ($user): void {
            if ($this->isLastActiveAdmin($user)) {
                throw LastActiveAdminException::cannotDelete();
            }
        });
    }

    public function isLastActiveAdmin(User $user): bool
    {
        if (! $user->exists) {
            return false;
        }

        $isAdmin = (bool) $user->getOriginal('is_admin');
        $isActive = (bool) $user->getOriginal('is_active');

        if (! $isAdmin || ! $isActive) {
            return false;
        }

        return User::query()->where('is_admin', true)->where('is_active', true)->count() <= 1;
    }

    private function createPrincipal(User $user): Principal
    {
        return Principal::query()->create([
            'user_id' => $user->getKey(),
            'uri' => 'principals/'.$user->username,
            'display_name' => $user->display_name,
            'email' => $user->email,
        ]);
    }

    private function password(mixed $password): ?string
    {
        if ($password === null) {
            return null;
        }

        if (! is_string($password) || $password === '') {
            return null;
        }

        return $password;
    }

    private function flag(mixed $value, bool $default): bool
    {
        return is_bool($value) ? $value : $default;
    }

    /**
     * One writer at a time for admin changes. Postgres uses a transaction
     * advisory lock. SQLite has no row locks, so a new transaction is
     * IMMEDIATE and an open one reserves the write lock before the count.
     * Re-entering the lock does not start another transaction.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withAdminLock(callable $callback): mixed
    {
        if (self::$adminLockDepth > 0) {
            return $callback();
        }

        self::$adminLockDepth++;

        try {
            return $this->runWithAdminLock($callback);
        } finally {
            self::$adminLockDepth--;
        }
    }

    /**
     * Join a transaction the caller already opened. First-admin boot can hold
     * a raw SQLite transaction that Laravel's transaction counter does not see.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function transaction(callable $callback): mixed
    {
        $connection = DB::connection();

        if ($connection->transactionLevel() === 0 && $connection->getPdo()->inTransaction()) {
            return $callback();
        }

        return $connection->transaction($callback);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function runWithAdminLock(callable $callback): mixed
    {
        $connection = DB::connection();

        if ($connection->transactionLevel() === 0 && $connection->getPdo()->inTransaction()) {
            return $callback();
        }

        if ($connection->transactionLevel() > 0) {
            $this->holdAdminLock($connection);

            return $callback();
        }

        if ($connection->getDriverName() === 'sqlite') {
            $pdo = $connection->getPdo();
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('BEGIN IMMEDIATE TRANSACTION');

            try {
                $result = $callback();
                $pdo->exec('COMMIT');

                return $result;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->exec('ROLLBACK');
                }

                throw $exception;
            }
        }

        return $connection->transaction(function () use ($callback, $connection) {
            $this->holdAdminLock($connection);

            return $callback();
        });
    }

    private function holdAdminLock(Connection $connection): void
    {
        if ($connection->getDriverName() === 'pgsql') {
            $connection->select('select pg_advisory_xact_lock(872461)');

            return;
        }

        if ($connection->getDriverName() === 'sqlite') {
            $connection->update('update users set id = id where 1 = 0');
        }
    }
}
