<?php

namespace App\Services;

use App\Exceptions\LastActiveAdminException;
use App\Models\Principal;
use App\Models\User;
use App\Services\Dav\AddressBookBackend;
use App\Services\Dav\CalendarBackend;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class UserService
{
    public const DEFAULT_CALENDAR_URI = 'default';

    public const DEFAULT_CALENDAR_NAME = 'Calendar';

    public const DEFAULT_CALENDAR_COLOR = '#1A73E8';

    public const DEFAULT_ADDRESS_BOOK_URI = 'default';

    public const DEFAULT_ADDRESS_BOOK_NAME = 'Contacts';

    public const EMAIL_TAKEN = 'The email has already been taken.';

    public const MIN_PASSWORD_LENGTH = 12;

    private static int $adminLockDepth = 0;

    /**
     * Create the user, their principal, and the default calendar and address book.
     * The principal is the calendar and address book home. sabre serves
     * calendars/{username} and addressbooks/{username} from that row.
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

            if ($this->emailTaken($email)) {
                throw new InvalidArgumentException(self::EMAIL_TAKEN);
            }

            $isAdmin = $this->flag($attributes['is_admin'] ?? false, false);
            $isActive = array_key_exists('is_active', $attributes)
                ? $this->flag($attributes['is_active'], true)
                : true;
            $password = $this->password($attributes['password'] ?? null);

            if ($isAdmin && $password === null) {
                throw new InvalidArgumentException('An admin must have a password.');
            }

            $user = User::withoutEvents(function () use ($username, $displayName, $email, $password, $isAdmin, $isActive): User {
                return User::query()->create([
                    'username' => $username,
                    'display_name' => $displayName,
                    'email' => $email,
                    'password' => $password,
                    'is_admin' => $isAdmin,
                    'is_active' => $isActive,
                ]);
            });

            $user->setRelation('principal', $this->syncPrincipal($user));
            $this->provisionDefaultCollections($user);

            return $user;
        });
    }

    /**
     * Give users that lack a principal the principal their homes are served
     * from, and default collections to users that have never had them.
     * Everyone else is skipped, so a restart does not visit every account.
     * A second run inserts nothing. A deleted default URI stays deleted.
     */
    public function backfillHomeCollections(): void
    {
        $users = User::query()
            ->where('collections_provisioned', false)
            ->orWhereDoesntHave('principal')
            ->orderBy('id')
            ->get();

        foreach ($users as $user) {
            $this->transaction(function () use ($user): void {
                $this->syncPrincipal($user);
                $this->provisionDefaultCollections($user);
            });
        }
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

                    if ($this->emailTaken($attributes['email'], (int) $user->getKey())) {
                        throw new InvalidArgumentException(self::EMAIL_TAKEN);
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

    /**
     * DAV accepts the email as a login and matches it without case, so two
     * accounts must not share an address that differs only in case.
     */
    public function emailTaken(string $email, ?int $exceptUserId = null): bool
    {
        return User::query()
            ->whereRaw('lower(email) = lower(?)', [trim($email)])
            ->when($exceptUserId !== null, fn ($query) => $query->whereKeyNot($exceptUserId))
            ->exists();
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

    /**
     * One UPDATE claims the user. The loser inserts nothing, so two prepares
     * cannot both create the defaults, and a delete is not undone.
     */
    private function provisionDefaultCollections(User $user): void
    {
        $claimed = DB::table('users')
            ->where('id', $user->getKey())
            ->where('collections_provisioned', false)
            ->update(['collections_provisioned' => true]);

        if ($claimed !== 1) {
            return;
        }

        $this->ensureDefaultCollections($user);
    }

    private function ensureDefaultCollections(User $user): void
    {
        $pdo = DB::connection()->getPdo();
        $principalUri = $user->principal_uri;

        (new CalendarBackend($pdo))->ensureCalendar(
            $principalUri,
            self::DEFAULT_CALENDAR_URI,
            self::DEFAULT_CALENDAR_NAME,
            self::DEFAULT_CALENDAR_COLOR,
        );

        (new AddressBookBackend($pdo))->ensureAddressBook(
            $principalUri,
            self::DEFAULT_ADDRESS_BOOK_URI,
            self::DEFAULT_ADDRESS_BOOK_NAME,
        );
    }

    private function createPrincipal(User $user): Principal
    {
        $uri = 'principals/'.$user->username;
        $now = now();

        Principal::query()->insertOrIgnore([
            'user_id' => $user->getKey(),
            'uri' => $uri,
            'display_name' => $user->display_name,
            'email' => $user->email,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $principal = Principal::query()->where('user_id', $user->getKey())->first();

        if (! $principal instanceof Principal || $principal->uri !== $uri) {
            throw new RuntimeException('The principal could not be created.');
        }

        return $principal;
    }

    private function password(mixed $password): ?string
    {
        if ($password === null) {
            return null;
        }

        if (! is_string($password) || $password === '') {
            return null;
        }

        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new InvalidArgumentException('The password must be at least '.self::MIN_PASSWORD_LENGTH.' characters.');
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
