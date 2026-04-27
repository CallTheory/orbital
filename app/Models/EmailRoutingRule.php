<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-client rule for routing an inbound email message to a
 * destination (queue, operator, AI persona, or discard).
 *
 * The InboundRouter evaluates a client's rules in priority ASC
 * order. `function`-type rules are tried first when the recipient
 * local-part has a `.function` suffix, then `default` rules
 * catch anything that didn't match. `from_pattern` and
 * `subject_pattern` rules can layer on top of either path.
 *
 * Parallel to the call-side `RoutingRule` model — same shape,
 * different enum values, not merged because voice and email
 * routing semantics diverge too much.
 */
class EmailRoutingRule extends Model
{
    use BelongsToTeam;
    use HasFactory;

    public const MATCH_FUNCTION = 'function';

    public const MATCH_FROM_PATTERN = 'from_pattern';

    public const MATCH_SUBJECT_PATTERN = 'subject_pattern';

    public const MATCH_DEFAULT = 'default';

    public const DESTINATION_QUEUE = 'queue';

    public const DESTINATION_OPERATOR = 'operator';

    public const DESTINATION_AGENT_PERSONA = 'agent_persona';

    public const DESTINATION_DISCARD = 'discard';

    protected $fillable = [
        'team_id',
        'name',
        'match_type',
        'match_pattern',
        'destination_type',
        'destination_id',
        'priority',
        'is_active',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'priority' => 'integer',
            'destination_id' => 'integer',
        ];
    }
}
