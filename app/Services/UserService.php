<?php

namespace App\Services;

use App\Exceptions\LastActiveAdminException;
use App\Models\Principal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class UserService
{
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

            $user = User::query()->create([
                'username' => $username,
                'display_name' => $displayName,
                'email' => $email,
                'password' => $password,
                'is_admin' => $isAdmin,
                'is_active' => $isActive,
                'timezone' => $this->optionalString($attributes['timezone'] ?? null, 'Timezone'),
                'locale' => $this->optionalString($attributes['locale'] ?? null, 'Locale'),
                'quota_bytes' => $this->optionalInt($attributes['quota_bytes'] ?? null),
            ]);

            $user->setRelation('principal', $this->createPrincipal($user));

            return $user;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $user, array $attributes): User
    {
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
            $this->syncPrincipal($user);

            return $user;
        });
    }

    public function delete(User $user): void
    {
        $this->transaction(function () use ($user): void {
            if ($user->delete() === false) {
                throw new RuntimeException('The user could not be deleted.');
            }
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

        $staysActiveAdmin = $user->is_admin && $user->is_active;

        if (! $staysActiveAdmin && $this->isLastActiveAdmin($user)) {
            throw LastActiveAdminException::cannotChange();
        }
    }

    public function assertCanDelete(User $user): void
    {
        if ($this->isLastActiveAdmin($user)) {
            throw LastActiveAdminException::cannotDelete();
        }
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
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if (is_bool($parsed)) {
                return $parsed;
            }
        }

        return $default;
    }

    private function optionalString(mixed $value, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("{$label} must be a string.");
        }

        return $value;
    }

    private function optionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new InvalidArgumentException('Quota must be an integer.');
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
}
