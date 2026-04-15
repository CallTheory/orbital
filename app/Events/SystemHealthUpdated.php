<?php

declare(strict_types=1);

namespace App\Events;

use App\Services\Health\HealthCheck;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast on every refresh of the system health snapshot, and on
 * any ack / ack-clear action. Carries the rolled-up state
 * (`ok`/`warn`/`down`) plus counts, which is all the status bar
 * and sidebar badge need to paint — the full card data stays in
 * the cache for surfaces that want the detail, no need to fan
 * the whole payload out over the websocket.
 *
 * Public `system-health` channel — platform-wide operational
 * signal, not tenant-scoped. All connected Filament tabs on every
 * panel pick this up and repaint in lockstep.
 *
 * ShouldBroadcastNow because health state is latency-sensitive —
 * if something just went red we want the bar to turn red right
 * now, not after a queue hop.
 */
class SystemHealthUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public string $status;

    public int $down;

    public int $warn;

    public string $badge;

    public string $color;

    /**
     * @param  array<int, HealthCheck>  $checks
     */
    public function __construct(array $checks)
    {
        $down = 0;
        $warn = 0;
        foreach ($checks as $c) {
            $s = $c->effectiveStatus();
            if ($s === HealthCheck::DOWN) {
                $down++;
            } elseif ($s === HealthCheck::WARN) {
                $warn++;
            }
        }

        $this->down = $down;
        $this->warn = $warn;

        // Same vocabulary everywhere — HealthCheck::statusLabel(),
        // SystemStatusBar, Dashboard::getNavigationBadge, and this
        // event all read in lockstep: ok / warn / down internally,
        // OK / Degraded / Problem on the badge.
        [$this->status, $this->badge, $this->color] = match (true) {
            $down > 0 => ['down', 'Problem', 'danger'],
            $warn > 0 => ['warn', 'Degraded', 'warning'],
            default => ['ok', 'OK', 'success'],
        };
    }

    public function broadcastOn(): Channel
    {
        return new Channel('system-health');
    }

    public function broadcastAs(): string
    {
        return 'system-health-updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'status' => $this->status,
            'down' => $this->down,
            'warn' => $this->warn,
            'badge' => $this->badge,
            'color' => $this->color,
            'at' => now()->toIso8601String(),
        ];
    }
}
