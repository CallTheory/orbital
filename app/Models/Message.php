<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message taken on behalf of a client — either by an AI agent
 * during a call or by a live operator via the workspace.
 *
 * Contains the caller's name, phone number, and reason for
 * calling. Linked to the client (team_id), optionally to the
 * call that generated it (call_log_id), optionally to the text
 * conversation it was written up from (message_thread_id), and to
 * whoever took it (created_by_user_id for operators, agent_persona_id
 * for AI).
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
        'message_thread_id',
        'caller_name',
        'caller_phone',
        'reason',
        'status',
        'urgency',
        'notes',
        'is_partial',
        'partial_reason',
        'missing_fields',
    ];

    protected function casts(): array
    {
        return [
            'is_partial' => 'boolean',
            'missing_fields' => 'array',
        ];
    }

    /**
     * Messages taken before the caller finished the script. Kept only
     * for clients whose policy asks for them — see
     * App\Services\Messages\PartialMessagePolicy.
     */
    public function scopePartial(Builder $query): Builder
    {
        return $query->where('is_partial', true);
    }

    public function scopeComplete(Builder $query): Builder
    {
        return $query->where('is_partial', false);
    }

    /**
     * Human-readable list of what the caller never supplied, for the
     * operator and portal lists.
     */
    public function missingFieldLabels(): string
    {
        $labels = [
            'caller_name' => 'name',
            'caller_phone' => 'callback number',
            'reason' => 'reason',
        ];

        return implode(', ', array_map(
            fn (string $field): string => $labels[$field] ?? $field,
            $this->missing_fields ?? [],
        ));
    }

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

    /**
     * The text conversation this was written up from, when it came off
     * the messaging channel rather than a call.
     */
    public function messageThread(): BelongsTo
    {
        return $this->belongsTo(MessageThread::class);
    }
}
