<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SipTrunk extends Model
{
    use BelongsToTeam;
    use SoftDeletes;

    protected $fillable = [
        'team_id',
        'name',
        'provider',
        'host',
        'port',
        'transport',
        'username',
        'password',
        'auth_type',
        'register',
        'inbound_context',
        'codecs',
        'max_channels',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'codecs' => 'array',
            'password' => 'encrypted',
            'register' => 'boolean',
            'is_active' => 'boolean',
            'port' => 'integer',
            'max_channels' => 'integer',
        ];
    }

    public function routingRules(): HasMany
    {
        return $this->hasMany(RoutingRule::class);
    }

    public function callLogs(): HasMany
    {
        return $this->hasMany(CallLog::class);
    }
}
