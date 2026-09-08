<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasConversationActivities;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One text conversation between a client's messaging endpoint and one
 * remote party.
 *
 * Status vocabulary is identical to EmailThread's, deliberately — an
 * operator moving between the inbox and the message queue should not
 * have to translate. See EmailThread for what each status means.
 *
 * Threading has no RFC822 headers to walk. Identity is
 * (endpoint, remote address), and a new inbound reopens the most recent
 * thread with that pair unless it's been closed longer than the
 * reopen window. See MessageThreadResolver.
 */
class MessageThread extends Model
{
    use BelongsToTeam;
    use HasConversationActivities;
    use HasFactory;

    public const STATUS_NEW = 'new';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_AWAITING_REPLY = 'awaiting_reply';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'team_id',
        'messaging_endpoint_id',
        'message_queue_id',
        'remote_address',
        'protocol',
        'status',
        'assigned_operator_id',
        'assigned_agent_persona_id',
        'fields',
        'last_message_at',
        'last_inbound_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(MessageEntry::class)->orderBy('occurred_at');
    }

    /**
     * Answering-service messages written up from this conversation.
     *
     * A conversation can produce more than one — a customer who texts
     * about two separate things over a week is two messages the client
     * has to act on, not one.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(MessagingEndpoint::class, 'messaging_endpoint_id');
    }

    public function queue(): BelongsTo
    {
        return $this->belongsTo(MessageQueue::class, 'message_queue_id');
    }

    public function assignedOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_operator_id');
    }

    public function assignedAgentPersona(): BelongsTo
    {
        return $this->belongsTo(AgentPersona::class, 'assigned_agent_persona_id');
    }

    public function isClaimed(): bool
    {
        return $this->assigned_operator_id !== null;
    }

    /**
     * Claim for an operator. Returns false if somebody else already has
     * it — two operators answering the same text conversation is worse
     * than a slow reply, because the customer sees both replies and
     * neither operator knows what the other said.
     *
     * The claim is a single conditional UPDATE rather than a read then a
     * write. The inbox polls every 20 seconds, so two operators seeing
     * the same unclaimed row and both clicking is ordinary, not
     * theoretical, and a check-then-write would let both through. The
     * database decides, exactly once.
     *
     * Idempotent for the operator who already holds it, so a double
     * click isn't a failure.
     */
    public function claimFor(User $user): bool
    {
        $status = in_array($this->status, [self::STATUS_NEW, self::STATUS_CLOSED], true)
            ? self::STATUS_IN_PROGRESS
            : $this->status;

        $claimed = static::withoutGlobalScopes()
            ->whereKey($this->getKey())
            ->where(function ($query) use ($user) {
                $query->whereNull('assigned_operator_id')
                    ->orWhere('assigned_operator_id', $user->id);
            })
            ->update([
                'assigned_operator_id' => $user->id,
                'status' => $status,
                'closed_at' => null,
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return false;
        }

        $this->forceFill([
            'assigned_operator_id' => $user->id,
            'status' => $status,
            'closed_at' => null,
        ])->syncOriginal();

        return true;
    }

    public function release(): void
    {
        $this->forceFill([
            'assigned_operator_id' => null,
            'status' => self::STATUS_NEW,
        ])->save();
    }

    public function close(): void
    {
        $this->forceFill([
            'status' => self::STATUS_CLOSED,
            'closed_at' => now(),
        ])->save();
    }

    /**
     * Preview text for list views — the most recent message's body,
     * trimmed. Loaded from the already-eager-loaded relation when
     * available so a list of fifty threads isn't fifty queries.
     */
    public function preview(int $length = 80): string
    {
        $latest = $this->relationLoaded('entries')
            ? $this->entries->last()
            : $this->entries()->reorder()->latest('occurred_at')->first();

        $body = trim((string) ($latest?->body ?? ''));

        if ($body === '') {
            return $latest?->media ? '(media)' : '(no content)';
        }

        return mb_strimwidth($body, 0, $length, '…');
    }
}
