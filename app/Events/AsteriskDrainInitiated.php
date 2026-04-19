<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired once per affected operator by AsteriskDrainService::drain()
 * so each user's operator panel can pop a toast warning them that
 * their softphone is pinned to the draining Asterisk. The payload
 * carries the minimum context the Livewire listener needs to render
 * the toast; filtering is done server-side now (we only dispatch to
 * users who need the nudge) so there's nothing to filter on the
 * client.
 *
 * User-scoped private channel — `operator.drain.{userId}` — rather
 * than the user's Laravel-notification channel. Keeps drain alerts
 * out of Filament's database-notification pipeline (which queues
 * via ShouldBroadcast) and lets us deliver synchronously with
 * ShouldBroadcastNow.
 */
class AsteriskDrainInitiated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly string $backend,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("operator.drain.{$this->userId}");
    }

    public function broadcastAs(): string
    {
        return 'asterisk-drain-initiated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'backend' => $this->backend,
            'at' => now()->toIso8601String(),
        ];
    }
}
