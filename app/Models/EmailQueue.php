<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Per-client email queue — a named bucket that routed inbound
 * threads land in, to be picked up by operators or an assigned
 * AI persona.
 *
 * Pointed at by EmailRoutingRule.destination_id when the rule's
 * destination_type is `queue`. The router stamps
 * email_threads.email_queue_id at route time so operators and
 * the thread list can filter by queue.
 *
 * Parallel to the call-side `CallQueue` model but with email
 * semantics — no ring timeouts, no wrapup, no music-on-hold.
 * Strategies describe how operators pick threads up, not how
 * Asterisk rings extensions.
 */
class EmailQueue extends Model
{
    use BelongsToTeam;
    use HasFactory;

    public const STRATEGY_ROUND_ROBIN = 'round_robin';

    public const STRATEGY_LONGEST_IDLE = 'longest_idle';

    public const STRATEGY_MANUAL = 'manual';

    public const STRATEGY_AI_FIRST = 'ai_first';

    protected $fillable = [
        'team_id',
        'name',
        'description',
        'strategy',
        'overflow_agent_persona_id',
        'agent_group_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function overflowAgent(): BelongsTo
    {
        return $this->belongsTo(AgentPersona::class, 'overflow_agent_persona_id');
    }

    /**
     * The operator group that works this queue. When set, only
     * group members see unclaimed threads in this queue. Null
     * means "open" — all operators can see it.
     */
    public function agentGroup(): BelongsTo
    {
        return $this->belongsTo(AgentGroup::class);
    }

    public function threads(): HasMany
    {
        return $this->hasMany(EmailThread::class, 'email_queue_id');
    }
}
