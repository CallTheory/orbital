<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message taken on behalf of a client — either by an AI agent
 * during a call or by a live operator via the workspace.
 *
 * Contains the caller's name, phone number, and reason for
 * calling. Linked to the client (team_id), optionally to the
 * call that generated it (call_log_id), and to whoever took
 * it (created_by_user_id for operators, agent_persona_id for AI).
 */
class Message extends Model
{
    use BelongsToTeam;
    use HasFactory;

    public const STATUS_NEW = 'new';

    public const STATUS_READ = 'read';

    public const STATUS_ARCHIVED = 'archived';

    public const URGENCY_NORMAL = 'normal';

    public const URGENCY_URGENT = 'urgent';

    protected $fillable = [
        'team_id',
        'created_by_user_id',
        'agent_persona_id',
        'call_log_id',
        'caller_name',
        'caller_phone',
        'reason',
        'status',
        'urgency',
        'notes',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function agentPersona(): BelongsTo
    {
        return $this->belongsTo(AgentPersona::class);
    }

    public function callLog(): BelongsTo
    {
        return $this->belongsTo(CallLog::class);
    }
}
