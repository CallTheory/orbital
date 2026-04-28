<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-client chat queue — interactive, session-shaped traffic
 * from an embeddable web widget, Slack, or Microsoft Teams. A
 * queue is bound to one `integration_type` and carries the
 * auth/config its transport needs.
 *
 * Sessions/message persistence are deferred — this model exists
 * to unblock orchestration assignment and the channels hub UI.
 */
class ChatQueue extends Model
{
    use BelongsToTeam;
    use HasFactory;

    public const STRATEGY_ROUND_ROBIN = 'round_robin';

    public const STRATEGY_LONGEST_IDLE = 'longest_idle';

    public const STRATEGY_MANUAL = 'manual';

    public const STRATEGY_AI_FIRST = 'ai_first';

    public const INTEGRATION_WEB_WIDGET = 'web_widget';

    public const INTEGRATION_SLACK = 'slack';

    public const INTEGRATION_TEAMS = 'teams';

    public const INTEGRATION_TYPES = [
        self::INTEGRATION_WEB_WIDGET,
        self::INTEGRATION_SLACK,
        self::INTEGRATION_TEAMS,
    ];

    protected $fillable = [
        'team_id',
        'orchestration_id',
        'name',
        'description',
        'strategy',
        'overflow_agent_persona_id',
        'agent_group_id',
        'is_active',
        'integration_type',
        'integration_config',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'integration_config' => 'array',
        ];
    }

    public function overflowAgent(): BelongsTo
    {
        return $this->belongsTo(AgentPersona::class, 'overflow_agent_persona_id');
    }

    /**
     * The orchestration this queue runs when a chat session
     * starts in it. Null = queue is just an inbox; no AI flow.
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
