<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * One message within a MessageThread — inbound or outbound.
 *
 * Named "entry" rather than "message" because `Message` is already
 * taken by the answering-service message (the thing an operator takes
 * on behalf of a client and the client reads in their portal). Those
 * are different concepts and confusing them in the model layer would be
 * a lasting source of bugs.
 *
 * Delivery status is first-class here in a way it isn't for email.
 * SMTP acceptance is close enough to delivery for mail; SMS is not.
 * A message can be accepted by the carrier and then silently fail at
 * the handset minutes later, and an operator who doesn't know that
 * will assume the customer was told something they never received.
 */
class MessageEntry extends Model
{
    use BelongsToTeam;
    use HasFactory;

    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    /** Inbound messages: nothing to deliver, we already have it. */
    public const STATUS_RECEIVED = 'received';

    /** Outbound: handed to the provider, not yet acknowledged. */
    public const STATUS_QUEUED = 'queued';

    /** Outbound: provider accepted it. */
    public const STATUS_SENT = 'sent';

    /** Outbound: the carrier confirmed the handset got it. */
    public const STATUS_DELIVERED = 'delivered';

    /** Outbound: carrier explicitly reported non-delivery. */
    public const STATUS_UNDELIVERED = 'undelivered';

    /** Outbound: we never got it to the provider at all. */
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'message_thread_id',
        'team_id',
        'direction',
        'from_address',
        'to_address',
        'body',
        'media',
        'provider_message_id',
        'provider',
        'delivery_status',
        'delivery_error',
        'sent_by_user_id',
        'agent_persona_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'media' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(MessageThread::class, 'message_thread_id');
    }

    public function sentByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function agentPersona(): BelongsTo
    {
        return $this->belongsTo(AgentPersona::class);
    }

    public function isInbound(): bool
    {
        return $this->direction === self::DIRECTION_INBOUND;
    }

    /**
     * Did this outbound message fail to reach the customer?
     *
     * Surfaced prominently in the operator UI: a failed reply is a
     * customer who is still waiting and doesn't know it.
     */
    public function failedToDeliver(): bool
    {
        return in_array($this->delivery_status, [self::STATUS_UNDELIVERED, self::STATUS_FAILED], true);
    }

    /**
     * Attachments, resolved to something a browser can open.
     *
     * Prefers our own stored copy over the provider's URL. The
     * provider's URL is not a copy: it expires, it needs the carrier's
     * credentials, and it disappears with the account. Once
     * FetchMessageMediaJob has run there is a real one, and this is
     * where the UI picks it up.
     *
     * Signed and short-lived rather than public — an MMS to an
     * answering service is somebody's insurance photograph or their
     * prescription, and a guessable permanent URL is not an access
     * control.
     *
     * @return array<int, array{url: ?string, content_type: ?string, filename: ?string, size: ?int, stored: bool}>
     */
    public function mediaItems(): array
    {
        $out = [];

        foreach ((array) ($this->media ?? []) as $item) {
            $item = (array) $item;
            $path = $item['storage_path'] ?? null;
            $url = $item['url'] ?? null;
            $stored = false;

            if (is_string($path) && $path !== '') {
                $resolved = $this->temporaryUrl((string) ($item['storage_disk'] ?? config('messaging.media.disk', 's3')), $path);

                if ($resolved !== null) {
                    $url = $resolved;
                    $stored = true;
                }
            }

            $out[] = [
                'url' => is_string($url) && $url !== '' ? $url : null,
                'content_type' => $item['content_type'] ?? null,
                'filename' => $item['filename'] ?? null,
                'size' => isset($item['size']) ? (int) $item['size'] : null,
                'stored' => $stored,
            ];
        }

        return $out;
    }

    private function temporaryUrl(string $disk, string $path): ?string
    {
        $minutes = (int) config('messaging.media.link_ttl_minutes', 15);

        try {
            return Storage::disk($disk)->temporaryUrl($path, now()->addMinutes($minutes));
        } catch (\Throwable $e) {
            // Not every disk driver signs URLs (the local driver
            // doesn't). Fall back rather than breaking the thread view
            // over an attachment link.
            try {
                return Storage::disk($disk)->url($path);
            } catch (\Throwable) {
                Log::warning('message media url unavailable', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        }
    }

    /**
     * Who sent it — an operator, an AI persona, or the customer.
     */
    public function authorLabel(): string
    {
        if ($this->isInbound()) {
            return $this->from_address;
        }

        return $this->sentByUser?->name
            ?? $this->agentPersona?->name
            ?? 'System';
    }
}
