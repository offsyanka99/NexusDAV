<?php

namespace App\Models;

use App\Services\UserService;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use InvalidArgumentException;

#[Fillable([
    'username',
    'display_name',
    'email',
    'password',
    'is_admin',
    'is_active',
    'timezone',
    'locale',
    'quota_bytes',
])]
#[Hidden(['password'])]
class User extends Authenticatable implements FilamentUser, HasName
{
    public const USERNAME_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,62}$/';

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->exists && $user->isDirty('username')) {
                $user->username = $user->getOriginal('username');
            }

            if (! $user->exists && preg_match(self::USERNAME_PATTERN, (string) $user->username) !== 1) {
                throw new InvalidArgumentException('Username must be lowercase and URL-safe.');
            }

            if ($user->is_admin && ! is_string($user->getAttributes()['password'] ?? null)) {
                throw new InvalidArgumentException('An admin must have a password.');
            }
        });

        static::updating(function (User $user): void {
            app(UserService::class)->assertCanSave($user);
        });

        static::deleting(function (User $user): void {
            app(UserService::class)->assertCanDelete($user);
        });

        static::saved(function (User $user): void {
            if ($user->wasRecentlyCreated || ! $user->wasChanged(['display_name', 'email'])) {
                return;
            }

            app(UserService::class)->syncPrincipal($user);
        });
    }

    /**
     * @return HasOne<Principal, $this>
     */
    public function principal(): HasOne
    {
        return $this->hasOne(Principal::class);
    }

    /**
     * @return HasMany<AppPassword, $this>
     */
    public function appPasswords(): HasMany
    {
        return $this->hasMany(AppPassword::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin' && $this->is_admin && $this->is_active;
    }

    public function getFilamentName(): string
    {
        if ($this->display_name !== '') {
            return $this->display_name;
        }

        if ($this->username !== '') {
            return $this->username;
        }

        return $this->email;
    }

    /**
     * The users table has no remember_token column. Ignore the value so
     * remember-me does not UPDATE a missing field.
     */
    public function setRememberToken($value): void {}

    public function resolvedTimezone(): string
    {
        $timezone = $this->timezone;

        if (is_string($timezone) && $timezone !== '') {
            return $timezone;
        }

        return (string) config('nexusdav.default_timezone');
    }

    public function resolvedLocale(): string
    {
        $locale = $this->locale;

        if (is_string($locale) && $locale !== '') {
            return $locale;
        }

        return (string) config('nexusdav.default_locale');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
            'quota_bytes' => 'integer',
        ];
    }
}
