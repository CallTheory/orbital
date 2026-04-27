<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One node in a client's intake-flow graph.
 *
 * A flow carries an ordered sequence of primitive steps (the
 * microscopic work the agent does) plus outbound transitions (the
 * edges to the next flow in the graph). A client has many flows;
 * one flow is the entry point resolved by the routing chain
 * (routing_rule → extension → persona default), and transitions
 * connect the rest.
 *
 * Canvas coordinates (`canvas_x`, `canvas_y`) are persisted node
 * positions for the Svelte Flow editor so the graph opens the same
 * way every time.
 */
class IntakeFlow extends Model
{
    use BelongsToTeam;
    use SoftDeletes;

    public const KIND_CALL_FLOW = 'call_flow';

    public const KIND_ACTION_GROUP = 'action_group';

    public const KIND_ACTION_TABLE = 'action_table';

    public const TRIGGER_INBOUND_PHONE = 'inbound_phone';

    public const TRIGGER_INBOUND_EMAIL = 'inbound_email';

    public const TRIGGER_INBOUND_SMS = 'inbound_sms';

    public const TRIGGER_INBOUND_WCTP = 'inbound_wctp';

    public const TRIGGER_OUTBOUND_PHONE = 'outbound_phone';

    public const TRIGGER_SUBFLOW = 'subflow';

    public const TRIGGER_MANUAL = 'manual';

    public const CHANNEL_TRIGGERS = [
        self::TRIGGER_INBOUND_PHONE,
        self::TRIGGER_INBOUND_EMAIL,
        self::TRIGGER_INBOUND_SMS,
        self::TRIGGER_INBOUND_WCTP,
        self::TRIGGER_OUTBOUND_PHONE,
    ];

    protected $fillable = [
        'team_id',
        'orchestration_id',
        'name',
        'description',
        'trigger_type',
        'kind',
        'is_active',
        'display_order',
        'canvas_x',
        'canvas_y',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'display_order' => 'integer',
            'canvas_x' => 'integer',
            'canvas_y' => 'integer',
        ];
    }

    /**
     * Parent orchestration — the named bundle this flow lives
     * inside. Every intake_flow belongs to exactly one orchestration
     * (NOT NULL FK).
     */
    public function orchestration(): BelongsTo
    {
        return $this->belongsTo(Orchestration::class);
    }

    /**
     * Ordered primitive steps that run on entry to this flow.
     */
    public function steps(): HasMany
    {
        return $this->hasMany(IntakeFlowStep::class, 'flow_id')->orderBy('position');
    }

    /**
     * Outbound transitions from this flow — evaluated in priority
     * order after the last step completes.
     */
    public function transitionsOut(): HasMany
    {
        return $this->hasMany(IntakeFlowTransition::class, 'from_flow_id')->orderBy('priority');
    }

    /**
     * Inbound transitions — other flows that can hand control to us.
     * Used by the editor to draw edges and by `isEntry()` to answer
     * "is anyone upstream of this flow?"
     */
    public function transitionsIn(): HasMany
    {
        return $this->hasMany(IntakeFlowTransition::class, 'to_flow_id');
    }

    /**
     * Personas that use this flow as their default.
     */
    public function defaultForPersonas(): HasMany
    {
        return $this->hasMany(AgentPersona::class, 'default_flow_id');
    }

    /**
     * Extensions that override their persona's default with this flow.
     */
    public function extensions(): HasMany
    {
        return $this->hasMany(Extension::class, 'intake_flow_id');
    }

    /**
     * Routing rules that force this flow for matched calls.
     */
    public function routingRules(): HasMany
    {
        return $this->hasMany(RoutingRule::class, 'intake_flow_id');
    }

    /**
     * Reactive rules attached to this flow or any of its steps
     * (Phase 4). The compiler merges them into the LLM prompt; the
     * runtime fires them on matching events during a call.
     */
    public function rules(): HasMany
    {
        return $this->hasMany(IntakeFlowRule::class, 'flow_id')->orderBy('priority');
    }

    /**
     * Is this flow something external (persona / extension / routing
     * rule) directly points at? "Entry" is computed — not a column —
     * because the same flow can be an entry for one trigger and a
     * mid-graph stop for another.
     */
    public function isEntry(): bool
    {
        return $this->defaultForPersonas()->exists()
            || $this->extensions()->exists()
            || $this->routingRules()->exists();
    }

    public function isChannelTrigger(): bool
    {
        return in_array($this->trigger_type, self::CHANNEL_TRIGGERS, true);
    }
}
