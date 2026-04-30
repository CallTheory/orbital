<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One audio file produced for a call.
 *
 * A single CallLog row can have many CallRecording rows because
 * we now record at multiple surfaces — rtpengine writes a pair
 * per SIP dialog (caller_in + caller_out), LiveKit Egress writes
 * one per room participant, and legacy Asterisk MixMonitor rows
 * (pre-rtpengine) live here too.
 *
 * Group multi-source recordings of the same call by `call_log_id`;
 * pair the rtpengine WAVs of the same dialog by `leg_uuid`.
 */
class CallRecording extends Model
{
    use BelongsToTeam;
    use HasFactory;

    public const SOURCE_RTPENGINE_EDGE = 'rtpengine_edge';

    public const SOURCE_LIVEKIT_EGRESS = 'livekit_egress';

    public const SOURCE_ASTERISK_MIXMONITOR = 'asterisk_mixmonitor';

    public const SOURCES = [
        self::SOURCE_RTPENGINE_EDGE,
        self::SOURCE_LIVEKIT_EGRESS,
        self::SOURCE_ASTERISK_MIXMONITOR,
    ];

    public const DIRECTION_CALLER_IN = 'caller_in';

    public const DIRECTION_CALLER_OUT = 'caller_out';

    public const DIRECTION_PARTICIPANT_TRACK = 'participant_track';

    public const DIRECTION_MIXED = 'mixed';

    public const DIRECTIONS = [
        self::DIRECTION_CALLER_IN,
        self::DIRECTION_CALLER_OUT,
        self::DIRECTION_PARTICIPANT_TRACK,
        self::DIRECTION_MIXED,
    ];

    protected $fillable = [
        'team_id',
        'call_log_id',
        'source',
        'direction',
        'leg_uuid',
        'participant_identity',
        'storage_path',
        'format',
        'size_bytes',
        'duration_ms',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'duration_ms' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function callLog(): BelongsTo
    {
        return $this->belongsTo(CallLog::class);
    }

    public function scopeFromRtpengine(Builder $query): Builder
    {
        return $query->where('source', self::SOURCE_RTPENGINE_EDGE);
    }

    public function scopeFromLivekit(Builder $query): Builder
    {
        return $query->where('source', self::SOURCE_LIVEKIT_EGRESS);
    }
}
