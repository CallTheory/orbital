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

    /**
     * Skills this queue requires from operators that should answer
     * its calls. Pivot carries `weight` (1–10) — higher = the queue
     * weights this skill more heavily when QueueMemberSyncer
     * computes member assignments and penalty.
     */
    public function requiredSkills(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'call_queue_required_skills')
            ->withPivot(['weight'])
            ->withTimestamps();
    }

    /**
     * The canonical Asterisk-side queue name. Client queues collide
     * if two clients both name a queue "support" — Asterisk's queue
     * namespace is global. We prefix every client queue with
     * `t{team_id}_` so Filament can show "Support" while Asterisk
     * stores `t42_support`. Null-team platform queues stay
     * unprefixed because there's only one of them per slug.
     *
     * Used by:
     *  - resources/views/asterisk/queues.blade.php (legacy generator)
     *  - resources/views/asterisk/extensions.blade.php (queue dispatch)
     *  - app/Services/Telephony/Realtime/QueueSyncer (ARA writer)
     *  - app/Services/Telephony/Realtime/QueueMemberSyncer (member rows)
     *
     * Slug normalization keeps the result valid as an Asterisk
     * context name (lowercase letters, digits, underscores).
     */
    public function asteriskName(): string
    {
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower((string) $this->name)) ?? '';
        $slug = trim($slug, '_');
        if ($slug === '') {
            $slug = 'queue';
        }

        if ($this->team_id === null) {
            return $slug;
        }

        return 't'.$this->team_id.'_'.$slug;
    }
}
