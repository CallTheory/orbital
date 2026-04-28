<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-client message queue — bucket for inbound text-shaped
 * traffic across SMS, MMS, RCS, SMPP, WCTP, and paging. One
 * queue can match multiple protocols so a "Support" queue
 * can take any text inbound regardless of transport.
 *
 * Parallel to EmailQueue with messaging-shaped matching:
 * `matched_addresses` carries phone numbers / shortcodes /
 * pager IDs; `matched_protocols` whitelists which transports
 * fall through to this queue.
 */
class MessageQueue extends Model
{
    use BelongsToTeam;
    use HasFactory;

    public const STRATEGY_ROUND_ROBIN = 'round_robin';

    public const STRATEGY_LONGEST_IDLE = 'longest_idle';

    public const STRATEGY_MANUAL = 'manual';

    public const STRATEGY_AI_FIRST = 'ai_first';

    public const PROTOCOL_SMS = 'sms';

    public const PROTOCOL_MMS = 'mms';

    public const PROTOCOL_RCS = 'rcs';

    public const PROTOCOL_SMPP = 'smpp';

    public const PROTOCOL_WCTP = 'wctp';

    public const PROTOCOL_PAGING = 'paging';

    public const PROTOCOLS = [
        self::PROTOCOL_SMS,
        self::PROTOCOL_MMS,
        self::PROTOCOL_RCS,
        self::PROTOCOL_SMPP,
        self::PROTOCOL_WCTP,
        self::PROTOCOL_PAGING,
    ];

    protected $fillable = [
        'team_id',
        'orchestration_id',
        'name',
        'description',
        'strategy',
        'matched_addresses',
        'matched_protocols',
        'overflow_agent_persona_id',
        'agent_group_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'matched_addresses' => 'array',
            'matched_protocols' => 'array',
        ];
    }

    public function overflowAgent(): BelongsTo
    {
        return $this->belongsTo(AgentPersona::class, 'overflow_agent_persona_id');
    }

    /**
     * The orchestration this queue runs when an inbound message
     * resolves to it. Null = queue is just an inbox; no AI flow.
     */
    public function orchestration(): BelongsTo
    {
        return $this->belongsTo(Orchestration::class);
    }

    /**
     * The operator group that works this queue. Null means
     * "open" — all operators can see it.
     */
    public function agentGroup(): BelongsTo
    {
        return $this->belongsTo(AgentGroup::class);
    }
}
