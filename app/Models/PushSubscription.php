<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A WebDAV-Push registration (docs/design/webdav-push.md). The client's
 * key and auth secret are encrypted with APP_KEY.
 *
 * @property int $id
 * @property string $registration
 * @property int $user_id
 * @property int|null $app_password_id
 * @property string $scope
 * @property string $path
 * @property string $topic
 * @property string $push_resource
 * @property string $public_key
 * @property string $auth_secret
 * @property string|null $content_update_depth
 * @property string|null $property_update_depth
 * @property list<string>|null $properties
 * @property Carbon $expires_at
 * @property Carbon|null $removed_at
 * @property Carbon|null $last_success_at
 * @property Carbon|null $last_failure_at
 * @property string|null $last_failure
 */
#[Fillable([
    'registration',
    'user_id',
    'app_password_id',
    'scope',
    'path',
    'topic',
    'push_resource',
    'public_key',
    'auth_secret',
    'content_update_depth',
    'property_update_depth',
    'properties',
    'expires_at',
    'removed_at',
    'last_success_at',
    'last_failure_at',
    'last_failure',
])]
#[Hidden(['public_key', 'auth_secret'])]
class PushSubscription extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Not expired and not waiting to be removed.
     *
     * @param  Builder<PushSubscription>  $query
     * @return Builder<PushSubscription>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now())->whereNull('removed_at');
    }

    /**
     * The host of the push service, the only part of the push resource that
     * may be logged or shown.
     */
    public function endpointHost(): string
    {
        $host = parse_url($this->push_resource, PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'public_key' => 'encrypted',
            'auth_secret' => 'encrypted',
            'properties' => 'array',
            'expires_at' => 'datetime',
            'removed_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }
}
