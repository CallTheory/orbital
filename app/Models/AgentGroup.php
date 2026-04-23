<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A platform-level pool of human agents and devices that can take calls.
 *
 * Client call queues reference one of these via call_queues.agent_group_id
 * — the queue determines the strategy and timing, the group determines
 * who actually rings.
 *
 * Members are polymorphic: a group can mix User records (staff with
 * softphones) and Extension records (hardware desk phones, ATAs, etc.).
 *
 * AI agents are NOT members of agent groups. They participate at the
 * routing layer instead, as routing-rule destinations or queue overflow.
 */
class AgentGroup extends Model
{
    protected $fillable = [
        'name',
        'label',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function members(): HasMany
    {
        return $this->hasMany(AgentGroupMember::class);
    }

    public function callQueues(): HasMany
    {
        return $this->hasMany(CallQueue::class);
    }

    public function emailQueues(): HasMany
    {
        return $this->hasMany(EmailQueue::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
