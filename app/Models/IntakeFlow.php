<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tenant-authored composition of intake goals — the ordered sequence of
 * small objectives the agent (or operator) walks through during a call.
 *
 * Flows are always tenant-scoped (team_id not null). Tenants compose
 * library goals by adding them as ordered steps; the AgentFlowCompiler
 * resolves a flow onto concrete LLM instructions + function schemas.
 */
class IntakeFlow extends Model
{
    use BelongsToTeam;
    use SoftDeletes;

    protected $fillable = [
        'team_id',
        'name',
        'description',
        'trigger_type',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Ordered steps in this flow.
     */
    public function steps(): HasMany
    {
        return $this->hasMany(IntakeFlowStep::class, 'flow_id')->orderBy('position');
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
}
