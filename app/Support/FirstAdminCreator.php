<?php

namespace App\Support;

use App\Models\User;
use App\Services\UserService;
use RuntimeException;

final class FirstAdminCreator
{
    public function __construct(private UserService $users) {}

    /**
     * Create the first admin, or return the existing one without changing it.
     */
    public function createIfMissing(?string $email, ?string $password): User
    {
        return $this->users->withAdminLock(function () use ($email, $password): User {
            $existing = User::query()->where('is_admin', true)->first();

            if ($existing instanceof User) {
                $this->users->syncPrincipal($existing);

                return $existing;
            }

            if (! is_string($email) || trim($email) === '' || ! is_string($password) || $password === '') {
                throw new RuntimeException('ADMIN_EMAIL and ADMIN_PASSWORD are required to create the first admin.');
            }

            return $this->create(trim($email), $password);
        });
    }

    public static function usernameFromEmail(string $email): string
    {
        $local = self::localPart($email);
        $username = mb_strtolower($local, 'UTF-8');
        $username = preg_replace('/[^a-z0-9_-]+/', '', $username) ?? '';
        $username = preg_replace('/([_-])\1+/', '$1', $username) ?? '';
        $username = ltrim($username, '_-');

        if (strlen($username) > 63) {
            $username = rtrim(substr($username, 0, 63), '_-');
        }

        if (preg_match(User::USERNAME_PATTERN, $username) !== 1) {
            throw new RuntimeException('Cannot derive a valid username from ADMIN_EMAIL.');
        }

        return $username;
    }

    public static function displayNameFromEmail(string $email): string
    {
        $local = self::localPart($email);

        if (mb_strlen($local) > 255) {
            return mb_substr($local, 0, 255);
        }

        return $local;
    }

    private function create(string $email, string $password): User
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('ADMIN_EMAIL must be a valid email address.');
        }

        $username = self::usernameFromEmail($email);

        if (User::query()->where('username', $username)->exists()) {
            throw new RuntimeException("Cannot create the first admin because the username [{$username}] is already in use.");
        }

        return $this->users->create([
            'username' => $username,
            'display_name' => self::displayNameFromEmail($email),
            'email' => $email,
            'password' => $password,
            'is_admin' => true,
            'is_active' => true,
        ]);
    }

    private static function localPart(string $email): string
    {
        $local = strstr(trim($email), '@', true);

        if (! is_string($local) || $local === '') {
            throw new RuntimeException('Cannot derive a valid username from ADMIN_EMAIL.');
        }

        return $local;
    }
}
