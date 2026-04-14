<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallLog extends Model
{
    use BelongsToTeam;

    protected $fillable = [
        'team_id',
        'unique_id',
        'linked_id',
        'channel',
        'sip_trunk_id',
        'extension_id',
        'agent_persona_id',
        'call_queue_id',
        'caller_id_name',
        'caller_id_num',
        'from_number',
        'to_number',
        'direction',
        'status',
        'disposition',
        'duration_seconds',
        'billable_seconds',
        'started_at',
        'answered_at',
        'ended_at',
        'recording_path',
        'recording_rx_path',
        'recording_tx_path',
        'recording_size_bytes',
        'transcript',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
            'billable_seconds' => 'integer',
            'recording_size_bytes' => 'integer',
        ];
    }

    public function sipTrunk(): BelongsTo
    {
        return $this->belongsTo(SipTrunk::class);
    }

    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class);
    }

    public function agentPersona(): BelongsTo
    {
        return $this->belongsTo(AgentPersona::class);
    }

    public function callQueue(): BelongsTo
    {
        return $this->belongsTo(CallQueue::class);
    }
}
