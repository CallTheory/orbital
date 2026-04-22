<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One voicemail left by a caller on a tenant's dumb-voicemail DID.
 *
 * Lifecycle:
 *   1. Asterisk records the WAV under /var/spool/asterisk/voicemail/...
 *   2. externnotify hook posts {mailbox, msg_num, recording_path}
 *      to Laravel; controller creates this row with transcription
 *      _status=pending.
 *   3. TranscribeVoicemailJob picks it up, runs the tenant's
 *      configured transcription provider, sets transcript +
 *      transcribed_at, uploads the WAV to SeaweedFS, clears the
 *      spool copy, and dispatches the email.
 *   4. emailed_at gets stamped when the Mailable finishes sending.
 */
class Voicemail extends Model
{
    use BelongsToTeam;
    use HasFactory;

    protected $fillable = [
        'team_id',
        'mailbox',
        'caller_id_num',
        'caller_id_name',
        'duration_seconds',
        'recording_path',
        's3_path',
        'transcript',
        'transcription_provider',
        'transcription_status',
        'transcription_error',
        'transcribed_at',
        'emailed_at',
    ];

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'transcribed_at' => 'datetime',
            'emailed_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
