<?php

namespace App\Models;

use App\Services\Dav\DavDataCleaner;
use App\Services\UserService;
use App\Support\LocaleCatalog;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use InvalidArgumentException;

/**
 * @property-read string $principal_uri
 */
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
            app(DavDataCleaner::class)->deleteForUser($user);
        });

        static::saved(function (User $user): void {
            if (! $user->wasChanged(['display_name', 'email']) && $user->principal()->exists()) {
                return;
            }

            app(UserService::class)->syncPrincipal($user);
        });
    }

    /**
     * The last-admin check runs in the updating hook. Keep the admin lock
     * until this write commits, or a second demotion can pass the same count.
     */
    public function save(array $options = []): bool
    {
        return app(UserService::class)->withAdminLock(
            fn (): bool => parent::save($options) !== false,
        );
    }

    public function delete()
    {
        return app(UserService::class)->withAdminLock(fn () => parent::delete());
    }

    /**
     * A blank password must not reach the hasher. Argon2id('') would accept a blank login.
     */
    protected function setPasswordAttribute(mixed $value): void
    {
        if (! is_string($value) || $value === '') {
            $value = null;
        }

        $this->attributes['password'] = $this->castAttributeAsHashedString('password', $value);
    }

    /**
     * DAV and the admin login match the email without case, so it is stored
     * in lower case. users_email_lower_unique backs this in the database.
     */
    protected function setEmailAttribute(mixed $value): void
    {
        $this->attributes['email'] = is_string($value) ? mb_strtolower(trim($value)) : $value;
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

    /**
     * calendarinstances and addressbooks store principals/{username}, not users.id.
     */
    protected function principalUri(): Attribute
    {
        return Attribute::get(fn (): string => 'principals/'.$this->username);
    }

    /**
     * @return HasMany<CalendarInstance, $this>
     */
    public function calendarInstances(): HasMany
    {
        // Owned calendars only. A calendar shared with this user is also a
        // calendarinstances row of theirs, with access 2 or 3.
        return $this->hasMany(CalendarInstance::class, 'principaluri', 'principal_uri')
            ->where('access', CalendarInstance::OWNER);
    }

    /**
     * @return HasMany<AddressBook, $this>
     */
    public function addressBooks(): HasMany
    {
        return $this->hasMany(AddressBook::class, 'principaluri', 'principal_uri');
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

    /**
     * The admin panel language: the user's own choice while it is still a
     * shipped language, else the server default, else English.
     */
    public function resolvedLocale(): string
    {
        $catalog = app(LocaleCatalog::class);

        return $catalog->supports($this->locale) ? (string) $this->locale : $catalog->default();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
            'quota_bytes' => 'integer',
            'collections_provisioned' => 'boolean',
        ];
    }
}
