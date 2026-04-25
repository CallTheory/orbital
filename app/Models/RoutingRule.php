<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RoutingRule extends Model
{
    use BelongsToTeam;
    use SoftDeletes;

    protected $fillable = [
        'team_id',
        'name',
        'sip_trunk_id',
        'match_type',
        'match_pattern',
        'time_condition',
        'destination_type',
        'destination_id',
        'intake_flow_id',
        'priority',
        'is_active',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'time_condition' => 'array',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function sipTrunk(): BelongsTo
    {
        return $this->belongsTo(SipTrunk::class);
    }

    /**
     * Highest-priority flow override — when set, every call matched by this
     * rule uses this flow regardless of persona/extension defaults.
     */
    public function intakeFlow(): BelongsTo
    {
        return $this->belongsTo(IntakeFlow::class, 'intake_flow_id');
    }
}
