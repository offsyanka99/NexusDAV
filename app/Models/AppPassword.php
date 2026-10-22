<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $revoked_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_used_at
 */
#[Fillable([
    'user_id',
    'name',
    'public_id',
    'token_hash',
    'revoked_at',
    'expires_at',
    'last_used_at',
])]
#[Hidden(['token_hash'])]
class AppPassword extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revoked_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }
}
