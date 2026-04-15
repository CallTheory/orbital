<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\Health\SystemHealthService;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Thin colored bar injected at the top of every Filament panel page.
 *
 * Primary update path is a Reverb broadcast: SystemHealthService
 * dispatches `SystemHealthUpdated` on the public `system-health`
 * channel every time the snapshot is re-probed (both automatic
 * refreshes and admin ack/clear actions). Every connected Filament
 * tab on every panel picks it up instantly via Livewire's
 * `echo:system-health,.system-health-updated` listener — no poll,
 * no page reload.
 *
 * A 60s `wire:poll` stays as a degraded-mode fallback: if Reverb is
 * down or the browser's websocket drops (laptop sleep, flaky wifi),
 * the bar still reconverges to the current state on its own. The
 * poll pulls from the 60s-cached snapshot on the hot path, so it's
 * free when nothing has changed.
 */
class SystemStatusBar extends Component
{
    public string $cls = '';

    public string $title = '';

    public function mount(): void
    {
        $this->load();
    }

    /**
     * Primary real-time update path. Reverb delivers the payload
     * straight from SystemHealthUpdated::broadcastWith() — status
     * rollup, counts, badge label, color. No DB / cache round-trip
     * because the event already carries everything the bar renders.
     * The dashboard cards still re-read cache on their own poll,
     * which is fine because the service just re-wrote it.
     *
     * @param  array<int, array<string, mixed>>  $payload
     */
    #[On('echo:system-health,.system-health-updated')]
    public function onHealthBroadcast(array $payload = []): void
    {
        // Echo hands Livewire the event data as the first element of
        // an array ([$data]). Unwrap defensively so a future Echo
        // format change doesn't silently break the bar.
        $data = $payload[0] ?? $payload;
        $down = (int) ($data['down'] ?? 0);
        $warn = (int) ($data['warn'] ?? 0);

        $this->applyRollup($down, $warn);
    }

    /**
     * Fallback loader used on initial mount and on the 60s poll.
     * Reads cache only — runAll() is the authoritative writer and
     * broadcaster, so we never re-probe from here. Cache misses
     * fall through to runAll which will broadcast on our behalf.
     */
    #[On('system-health-updated')]
    public function load(): void
    {
        $checks = Cache::get('system_health:checks');

        if (! is_array($checks) || empty($checks)) {
            // Cache miss — re-run probes. runAll writes cache and
            // dispatches SystemHealthUpdated, which loops back into
            // onHealthBroadcast for every OTHER tab. This tab's own
            // paint happens below via the rollup we compute inline.
            $checks = app(SystemHealthService::class)->runAll(useCache: false);
        }

        $down = 0;
        $warn = 0;
        foreach ($checks as $c) {
            if (is_object($c) && method_exists($c, 'effectiveStatus')) {
                $status = $c->effectiveStatus();
            } else {
                $status = is_object($c) ? $c->status : ($c['status'] ?? null);
            }
            if ($status === 'down') {
                $down++;
            } elseif ($status === 'warn') {
                $warn++;
            }
        }

        $this->applyRollup($down, $warn);
    }

    /**
     * One place to compute classes, tooltip text, and the sidebar
     * badge browser event. Shared by both the Reverb broadcast
     * listener and the cache-backed fallback loader so both paths
     * produce identical DOM state.
     *
     * Labels are in lockstep with HealthCheck::statusLabel() and
     * Dashboard::getNavigationBadge — one vocabulary across every
     * surface: OK / Degraded / Problem.
     */
    private function applyRollup(int $down, int $warn): void
    {
        [$this->cls, $this->title] = match (true) {
            $down > 0 => ['is-down', "{$down} component(s) with problems".($warn ? ", {$warn} degraded" : '')],
            $warn > 0 => ['is-warn', "{$warn} component(s) degraded"],
            default => ['is-ok', 'All systems OK'],
        };

        $badge = match (true) {
            $down > 0 => 'Problem',
            $warn > 0 => 'Degraded',
            default => 'OK',
        };
        $color = match (true) {
            $down > 0 => 'danger',
            $warn > 0 => 'warning',
            default => 'success',
        };

        // Browser event for the sidebar nav-item sync script in
        // panel-styles.blade.php to pick up and patch the Status
        // entry's badge in place.
        $this->dispatch('orbital-status-update', badge: $badge, color: $color);
    }

    public function render()
    {
        return view('livewire.system-status-bar');
    }
}
