<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Polymorphic pivot row connecting an AgentGroup to either a User
 * (platform staff) or an Extension (hardware SIP device).
 */
class AgentGroupMember extends Model
{
    protected $fillable = [
        'agent_group_id',
        'member_type',
        'member_id',
        'priority',
        'penalty',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'penalty' => 'integer',
        ];
    }

    public function agentGroup(): BelongsTo
    {
        return $this->belongsTo(AgentGroup::class);
    }

    public function member(): MorphTo
    {
        return $this->morphTo();
    }
}
