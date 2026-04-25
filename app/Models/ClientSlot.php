<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A declared variable in a client's intake-flow graph.
 *
 * Slots are the typed placeholders that `gather_*` steps write, that
 * `save_message` persists, and that transition conditions test. They
 * live at the client (team) level because a multi-flow graph passes
 * slot values across flow boundaries — a slot written in one flow
 * must still resolve in the next flow's transition evaluation.
 *
 * The `type` enum governs the primary-input the editor renders for
 * collection (phone input for `phone`, date picker for `date`, a
 * select seeded from `choices` for `choice`) and the validation the
 * runtime applies before persisting a value.
 */
class ClientSlot extends Model
{
    use BelongsToTeam;

    // Phase 3 added typed-input slot kinds matching the gather_X family
    // of primitives: datetime (date+time), duration (normalised HH:MM:SS
    // or seconds), masked (raw string collected against a mask pattern),
    // address (structured JSON in `choices` metadata), credit_card
    // (masked PAN + last4 + exp).
    public const TYPES = [
        'string',
        'phone',
        'email',
        'number',
        'boolean',
        'date',
        'datetime',
        'duration',
        'masked',
        'choice',
        'address',
        'credit_card',
    ];

    protected $fillable = [
        'team_id',
        'name',
        'type',
        'choices',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'choices' => 'array',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
