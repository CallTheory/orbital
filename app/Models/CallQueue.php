<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CallQueue extends Model
{
    use BelongsToTeam;
    use SoftDeletes;

    protected $fillable = [
        'team_id',
        'name',
        'strategy',
        'timeout',
        'retry',
        'wrapup_time',
        'max_callers',
        'music_on_hold',
        'join_empty',
        'leave_when_empty',
        'overflow_agent_persona_id',
        'agent_group_id',
    ];

    protected function casts(): array
    {
        return [
            'timeout' => 'integer',
            'retry' => 'integer',
            'wrapup_time' => 'integer',
            'max_callers' => 'integer',
            'join_empty' => 'boolean',
            'leave_when_empty' => 'boolean',
        ];
    }

    /**
     * The platform-level pool of humans/devices that ring when this queue
     * activates. Optional — a queue with no group is configuration-incomplete
     * and won't ring anyone until a group is assigned.
     */
    public function agentGroup(): BelongsTo
    {
        return $this->belongsTo(AgentGroup::class);
    }

    public function overflowAgent(): BelongsTo
    {
        return $this->belongsTo(AgentPersona::class, 'overflow_agent_persona_id');
    }

    public function callLogs(): HasMany
    {
        return $this->hasMany(CallLog::class);
    }
}
