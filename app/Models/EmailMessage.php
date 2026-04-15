<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Inbound or outbound email message persisted after Haraka's SMTP
 * shim POSTs a webhook to Laravel.
 *
 * Lifecycle: Haraka → InboundMailController writes a stub row
 * with `routing_status=pending` + raw blob in MinIO → queue job
 * parses, routes, fills in header + body columns, flips status.
 *
 * Phase 2 attaches these to EmailThread and requires team_id.
 * Phase 3 adds outbound rows when operators / AI reply.
 */
class EmailMessage extends Model
{
    use BelongsToTeam;
    use HasFactory;

    protected $fillable = [
        'team_id',
        'thread_id',
        'direction',
        'raw_storage_path',
        'message_id',
        'in_reply_to',
        'references',
        'from_address',
        'from_name',
        'to_addresses',
        'cc_addresses',
        'subject',
        'body_text',
        'body_html',
        'received_at',
        'processed_at',
        'routing_status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'references' => 'array',
            'to_addresses' => 'array',
            'cc_addresses' => 'array',
            'metadata' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(EmailThread::class, 'thread_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmailAttachment::class, 'email_message_id');
    }
}
