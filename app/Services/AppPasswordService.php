<?php

namespace App\Services;

use App\Models\AppPassword;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final class AppPasswordService
{
    public const SEPARATOR = '.';

    private const LAST_USED_INTERVAL_SECONDS = 60;

    /**
     * Issue a token and store only the SHA-256 hash of its secret.
     *
     * The plaintext is returned once and is not written to the database.
     * High-entropy tokens are looked up by public id, so a slow password hash is unnecessary.
     *
     * @return array{app_password: AppPassword, token: string}
     */
    public function issue(User $user, string $name, DateTimeInterface|string|null $expiresAt = null): array
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 255) {
            throw new InvalidArgumentException('App password name must be between 1 and 255 characters.');
        }

        $publicId = $this->newPublicId();
        $secret = $this->newSecret();
        $appPassword = AppPassword::query()->create([
            'user_id' => $user->getKey(),
            'name' => $name,
            'public_id' => $publicId,
            'token_hash' => $this->hashSecret($secret),
            'expires_at' => $this->expiresAt($expiresAt),
        ]);

        return [
            'app_password' => $appPassword,
            'token' => $publicId.self::SEPARATOR.$secret,
        ];
    }

    public function findByPublicId(string $publicId): ?AppPassword
    {
        return AppPassword::query()->where('public_id', $publicId)->first();
    }

    /**
     * Look up the public id, then compare the secret with hash_equals.
     * Revoked and expired tokens do not match.
     */
    public function verify(string $token): ?AppPassword
    {
        $parts = explode(self::SEPARATOR, $token, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        [$publicId, $secret] = $parts;
        $appPassword = $this->findByPublicId($publicId);

        if (! $appPassword instanceof AppPassword) {
            return null;
        }

        $known = $this->hashSecret($secret);

        if (strlen($appPassword->token_hash) !== strlen($known) || ! hash_equals($appPassword->token_hash, $known)) {
            return null;
        }

        if ($appPassword->revoked_at !== null) {
            return null;
        }

        if ($appPassword->expires_at?->isPast()) {
            return null;
        }

        return $appPassword;
    }

    /**
     * Keep the row and refuse the token from now on.
     */
    public function revoke(AppPassword $appPassword): void
    {
        if ($appPassword->revoked_at !== null) {
            return;
        }

        $appPassword->forceFill(['revoked_at' => now()])->save();
    }

    /**
     * Record use at most once per LAST_USED_INTERVAL_SECONDS.
     */
    public function markUsed(AppPassword $appPassword): void
    {
        $now = now();
        $cutoff = $now->copy()->subSeconds(self::LAST_USED_INTERVAL_SECONDS);

        $updated = AppPassword::query()
            ->whereKey($appPassword->getKey())
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('last_used_at')
                    ->orWhere('last_used_at', '<=', $cutoff);
            })
            ->update(['last_used_at' => $now]);

        if ($updated > 0) {
            $appPassword->refresh();
        }
    }

    public function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    private function expiresAt(DateTimeInterface|string|null $expiresAt): ?Carbon
    {
        if ($expiresAt instanceof Carbon) {
            return $expiresAt;
        }

        if ($expiresAt instanceof DateTimeInterface) {
            return Carbon::instance($expiresAt);
        }

        if (! is_string($expiresAt) || trim($expiresAt) === '') {
            return null;
        }

        return Carbon::parse($expiresAt);
    }

    private function newSecret(): string
    {
        return $this->base64Url(random_bytes(32));
    }

    private function newPublicId(): string
    {
        return $this->base64Url(random_bytes(9));
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
