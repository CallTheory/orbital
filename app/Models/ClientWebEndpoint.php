<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A named REST / SOAP endpoint the client's flows can call via the
 * `web_call` primitive. `credentials` is cast to encrypted-array so
 * auth tokens / passwords sit encrypted at rest.
 */
class ClientWebEndpoint extends Model
{
    use BelongsToTeam;
    use SoftDeletes;

    public const AUTH_NONE = 'none';

    public const AUTH_BASIC = 'basic';

    public const AUTH_BEARER = 'bearer';

    public const AUTH_API_KEY = 'api_key';

    public const AUTH_OAUTH2 = 'oauth2';

    public const AUTH_TYPES = [
        self::AUTH_NONE,
        self::AUTH_BASIC,
        self::AUTH_BEARER,
        self::AUTH_API_KEY,
        self::AUTH_OAUTH2,
    ];

    protected $fillable = [
        'team_id',
        'name',
        'base_url',
        'auth_type',
        'credentials',
        'default_headers',
        'timeout_seconds',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'default_headers' => 'array',
            'timeout_seconds' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
