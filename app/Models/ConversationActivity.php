<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Audit trail for operator and AI actions on any conversation-like
 * model — email threads, chat sessions, call logs, etc.
 *
 * Each row is one discrete action (claimed, replied, forwarded,
 * closed, reopened, assigned, note…) with optional metadata
 * capturing action-specific context like the reply body or
 * forward recipient.
 *
 * The polymorphic `subject` relationship lets any model opt in
 * by adding the HasConversationActivities trait.
 */
class ConversationActivity extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'subject_type',
        'subject_id',
        'user_id',
        'agent_persona_id',
        'action',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agentPersona(): BelongsTo
    {
        return $this->belongsTo(AgentPersona::class);
    }

    /**
     * Log an activity against any conversation subject.
     */
    public static function log(
        Model $subject,
        string $action,
        ?array $metadata = null,
        ?User $user = null,
        ?AgentPersona $agentPersona = null,
    ): static {
        return static::create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'user_id' => $user?->id,
            'agent_persona_id' => $agentPersona?->id,
            'action' => $action,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
