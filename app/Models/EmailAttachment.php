<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Index row for a file attached to an inbound email.
 *
 * The actual bytes live in MinIO at `storage_path`; this table
 * is just a searchable index + MIME metadata so the operator UI
 * can list, preview, and download attachments without pulling
 * the full message payload from the raw blob.
 *
 * Inline attachments (images embedded in HTML via `cid:`
 * references) get `inline=true` and their `content_id` set so
 * the thread viewer can rewrite the HTML to point at pre-signed
 * MinIO URLs at render time.
 */
class EmailAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'email_message_id',
        'filename',
        'content_type',
        'size_bytes',
        'storage_path',
        'inline',
        'content_id',
    ];

    protected function casts(): array
    {
        return [
            'inline' => 'boolean',
            'size_bytes' => 'integer',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'email_message_id');
    }
}
