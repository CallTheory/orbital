<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A DID (phone number) provisioned through one of the platform's SIP trunks
 * and assigned to a tenant.
 *
 * Tenants typically have multiple DIDs across different carriers/trunks for
 * failover. The `priority` column orders them — lower number wins.
 *
 * These are platform-owned numbers, NOT customer-brought-along ones.
 */
class TenantDid extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'team_id',
        'sip_trunk_id',
        'number',
        'label',
        'priority',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function sipTrunk(): BelongsTo
    {
        return $this->belongsTo(SipTrunk::class);
    }
}
