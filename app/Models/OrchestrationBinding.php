<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-(client, orchestration, binding_key) mapping from a stable
 * binding key declared in an orchestration's step_params to a concrete
 * team-scoped resource (agent persona, queue, extension, etc).
 *
 * This is the indirection layer that lets a single Orchestration row
 * be used by many clients without being duplicated. Each client owns
 * its own bindings rows for the orchestrations it uses; the runtime
 * compiler resolves binding keys against the running client's bindings.
 *
 * For per-client (non-shared) orchestrations, bindings are still used
 * — they just live alongside the orchestration's owning team and are
 * managed implicitly by the editor when the author picks a concrete
 * persona/queue/extension.
 */
class OrchestrationBinding extends Model
{
    use BelongsToTeam;

    public const TYPE_AGENT_PERSONA = 'agent_persona';

    public const TYPE_CALL_QUEUE = 'call_queue';

    public const TYPE_EMAIL_QUEUE = 'email_queue';

    public const TYPE_MESSAGE_QUEUE = 'message_queue';

    public const TYPE_CHAT_QUEUE = 'chat_queue';

    public const TYPE_EXTENSION = 'extension';

    public const TYPE_DID_SET = 'did_set';

    public const TYPE_KNOWLEDGE_STORE = 'knowledge_store';

    public const RESOURCE_TYPES = [
        self::TYPE_AGENT_PERSONA,
        self::TYPE_CALL_QUEUE,
        self::TYPE_EMAIL_QUEUE,
        self::TYPE_MESSAGE_QUEUE,
        self::TYPE_CHAT_QUEUE,
        self::TYPE_EXTENSION,
        self::TYPE_DID_SET,
        self::TYPE_KNOWLEDGE_STORE,
    ];

    protected $fillable = [
        'team_id',
        'orchestration_id',
        'binding_key',
        'resource_type',
        'resource_id',
        'resource_ids',
    ];

    protected function casts(): array
    {
        return [
            'resource_id' => 'integer',
            'resource_ids' => 'array',
        ];
    }

    public function orchestration(): BelongsTo
    {
        return $this->belongsTo(Orchestration::class);
    }

    /**
     * True when this binding holds a list of resources (DID set,
     * extension list, knowledge-store list) rather than a single ID.
     */
    public function isMulti(): bool
    {
        return $this->resource_ids !== null;
    }
}
