<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Broadcast when an inbound email message fails processing.
 *
 * Listened to by the admin panel's Failed Inbound Mail page
 * and sidebar badge so the count updates in real time without
 * polling.
 */
class InboundMailFailed implements ShouldBroadcastNow
{
    public function __construct(
        public int $messageId,
        public int $failedCount,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('admin-alerts');
    }

    public function broadcastAs(): string
    {
        return 'inbound-mail-failed';
    }
}
