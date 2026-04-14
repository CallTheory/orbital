<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared mid-call state keyed on the LiveKit session/room name.
 *
 * Not using BelongsToTeam because this model gets accessed from both
 * sides: the agent worker calls into Laravel before the tenant context
 * is established, and the operator reads it via the web panel under
 * the normal team scope. Isolation is enforced at the controller level
 * instead — see CallSessionController.
 */
class CallSessionState extends Model
{
    protected $fillable = [
        'session_key',
        'team_id',
        'agent_persona_id',
        'fields',
        'active_step',
        'operator_owned',
        'last_field_at',
    ];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'active_step' => 'integer',
            'operator_owned' => 'boolean',
            'last_field_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(AgentPersona::class, 'agent_persona_id');
    }

    /**
     * Set or update one field. Returns the updated map.
     *
     * @return array<string, mixed>
     */
    public function captureField(string $key, mixed $value): array
    {
        $fields = $this->fields ?? [];
        $fields[$key] = $value;
        $this->fields = $fields;
        $this->last_field_at = now();
        $this->save();
        return $fields;
    }
}
