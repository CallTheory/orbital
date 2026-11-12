<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CallLog extends Model
{
    use BelongsToTeam;

    protected $fillable = [
        'team_id',
        'unique_id',
        'linked_id',
        'sip_call_id',
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

    /** Status values Call History shows, with their labels. */
    public const STATUSES = [
        'completed' => 'Completed',
        'missed' => 'Missed',
        'busy' => 'Busy',
        'failed' => 'Failed',
        'ai_unavailable' => 'AI unavailable',
    ];

    public static function statusLabel(?string $status): string
    {
        return self::STATUSES[$status] ?? ucfirst(str_replace('_', ' ', (string) $status));
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'completed' => 'success',
            'missed', 'busy' => 'warning',
            'failed', 'ai_unavailable' => 'danger',
            'in_progress' => 'info',
            default => 'gray',
        };
    }

    /** m:ss under an hour, h:mm:ss from an hour; a dash for zero. */
    public static function formatDuration(?int $seconds): string
    {
        $seconds = (int) $seconds;
        if ($seconds <= 0) {
            return '—';
        }

        return $seconds >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
            : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
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

    /**
     * Per-leg recording rows produced for this call. Replaces the
     * legacy `recording_*` path columns going forward — those stay
     * populated until consumers migrate, but new pipeline paths
     * (rtpengine recording-daemon, LiveKit Egress) write here.
     */
    public function recordings(): HasMany
    {
        return $this->hasMany(CallRecording::class);
    }

    /**
     * The AI session(s) that took part in this call, linked through
     * Asterisk's linkedid (the X-Orbital-Call header on the AI leg).
     */
    public function callSessions(): HasMany
    {
        return $this->hasMany(CallSessionState::class, 'linked_id', 'linked_id');
    }
}
