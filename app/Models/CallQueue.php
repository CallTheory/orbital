<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CallQueue extends Model
{
    use BelongsToTeam;
    use SoftDeletes;

    protected static function booted(): void
    {
        // Any queue saved without a template FK picks up the platform
        // default automatically. Covers Filament forms, seeders, and
        // any future API callers without forcing each to know about
        // the template system.
        static::saving(function (self $queue): void {
            if ($queue->strategy_template_id === null) {
                $defaultId = QueueStrategyTemplate::where('is_default', true)->value('id');
                if ($defaultId !== null) {
                    $queue->strategy_template_id = $defaultId;
                }
            }
        });
    }

    protected $fillable = [
        'strategy_template_id',
        'orchestration_id',
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
     * DIDs that route inbound calls to this queue. Each DID can
     * belong to at most one queue (pivot `call_queue_dids` enforces
     * unique `client_did_id`).
     */
    public function dids(): BelongsToMany
    {
        return $this->belongsToMany(
            ClientDid::class,
            'call_queue_dids',
            'call_queue_id',
            'client_did_id',
        )->withTimestamps();
    }

    /**
     * Platform-level strategy template this queue picks. The
     * template carries `strategy` / `timeout` / `retry` /
     * `wrapup_time` so clients don't hand-tune them. Legacy queues
     * (pre-migration) may have a null template FK; QueueSyncer
     * reads `$this->strategyTemplate` if present, else falls back
     * to the legacy columns on the row.
     */
    public function strategyTemplate(): BelongsTo
    {
        return $this->belongsTo(QueueStrategyTemplate::class, 'strategy_template_id');
    }

    /**
     * The orchestration this queue runs when a call comes in. Null
     * means the queue exists but has no flow logic attached —
     * inbound calls still hunt for operators per the queue strategy
     * but the AI side does nothing until an author assigns one.
     */
    public function orchestration(): BelongsTo
    {
        return $this->belongsTo(Orchestration::class);
    }

    /**
     * Skills this queue requires from operators that should answer
     * its calls. Pivot carries `weight` (1–10) — higher = the queue
     * weights this skill more heavily when QueueMemberSyncer
     * computes member assignments and penalty.
     */
    public function requiredSkills(): BelongsToMany
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
