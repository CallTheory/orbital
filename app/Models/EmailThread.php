<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Groups related email messages into a conversation.
 *
 * The ThreadResolver chains messages via RFC822 `Message-ID` /
 * `In-Reply-To` / `References` headers, falling back to a
 * sender + subject-root match within a 7-day window when the
 * headers don't resolve (e.g. a plain reply from a tenant
 * whose mail client stripped the References chain).
 *
 * A thread's `status` mirrors how operators work it:
 *
 *   new           → arrived, nobody's touched it
 *   in_progress   → operator/AI actively handling
 *   awaiting_reply → we sent something, watching for the return
 *   closed        → handled, no further action expected
 *
 * `participants` is the rolling set of every address that's
 * appeared on any message — useful for "Reply all" and search.
 */
class EmailThread extends Model
{
    use BelongsToTeam;
    use HasFactory;

    public const STATUS_NEW = 'new';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_AWAITING_REPLY = 'awaiting_reply';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'team_id',
        'subject_root',
        'participants',
        'status',
        'assigned_operator_id',
        'assigned_agent_persona_id',
        'email_queue_id',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'participants' => 'array',
            'last_message_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class, 'thread_id')->orderBy('received_at');
    }

    public function assignedOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_operator_id');
    }

    public function assignedAgentPersona(): BelongsTo
    {
        return $this->belongsTo(AgentPersona::class, 'assigned_agent_persona_id');
    }

    public function emailQueue(): BelongsTo
    {
        return $this->belongsTo(EmailQueue::class, 'email_queue_id');
    }
}
