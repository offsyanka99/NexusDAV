<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $occurred_at
 * @property string $source
 * @property string $kind
 * @property string $ip
 * @property string $username
 * @property string|null $public_id
 * @property string|null $request_id
 * @property string $method
 * @property string $path
 * @property int $status
 * @property string $reason
 * @property int $repeat_count
 * @property int $bucket
 */
#[Fillable([
    'occurred_at',
    'source',
    'kind',
    'ip',
    'username',
    'public_id',
    'request_id',
    'method',
    'path',
    'status',
    'reason',
    'repeat_count',
    'bucket',
])]
class DiagnosticEvent extends Model
{
    public const SOURCE_ADMIN = 'admin';

    public const SOURCE_DAV = 'dav';

    public const KIND_AUTH_FAILED = 'auth_failed';

    public const KIND_HTTP_ERROR = 'http_error';

    /**
     * Summary row for one address once it passed diagnostics.max_rows_per_address in a window.
     */
    public const REASON_MANY_USERNAMES = 'many usernames';

    /**
     * Summary row for DAV errors from one address past the same limit.
     */
    public const REASON_MANY_REQUESTS = 'many requests';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'status' => 'integer',
            'repeat_count' => 'integer',
            'bucket' => 'integer',
        ];
    }
}
