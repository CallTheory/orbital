<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (team, channel_type) — "which flow graph runs when
 * this client's <channel> fires?".
 *
 * `flow_graph_id` is nullable: null means the channel is inert (no
 * graph assigned). Validation elsewhere enforces that assigned
 * graphs must have `status=active` at save time.
 *
 * A FlowGraph delete does NOT destroy the assignment row — the FK
 * is `set null` so the channel just goes inert until an author
 * reassigns another active graph.
 */
class ClientChannelAssignment extends Model
{
    use BelongsToTeam;

    protected static function booted(): void
    {
        // Channel assignments can only reference active graphs.
        // Null is allowed (channel goes inert). Fails loudly so API
        // callers and Filament forms both see a clear validation
        // error rather than a silent save of a draft graph that
        // won't actually run.
        static::saving(function (self $assignment): void {
            if ($assignment->flow_graph_id === null) {
                return;
            }
            $graph = FlowGraph::find($assignment->flow_graph_id);
            if (! $graph || $graph->status !== FlowGraph::STATUS_ACTIVE) {
                throw new \InvalidArgumentException(
                    'Only active flow graphs can be assigned to a channel. '
                    .'Promote the graph first, or pick a different one.'
                );
            }
        });
    }

    public const CHANNEL_INBOUND_PHONE = 'inbound_phone';

    public const CHANNEL_INBOUND_EMAIL = 'inbound_email';

    public const CHANNEL_INBOUND_SMS = 'inbound_sms';

    public const CHANNEL_INBOUND_WCTP = 'inbound_wctp';

    public const CHANNEL_OUTBOUND_PHONE = 'outbound_phone';

    public const CHANNELS = [
        self::CHANNEL_INBOUND_PHONE,
        self::CHANNEL_INBOUND_EMAIL,
        self::CHANNEL_INBOUND_SMS,
        self::CHANNEL_INBOUND_WCTP,
        self::CHANNEL_OUTBOUND_PHONE,
    ];

    protected $fillable = [
        'team_id',
        'channel_type',
        'flow_graph_id',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function flowGraph(): BelongsTo
    {
        return $this->belongsTo(FlowGraph::class);
    }
}
