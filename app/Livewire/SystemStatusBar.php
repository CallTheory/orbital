<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\Health\SystemHealthService;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Thin colored bar injected at the top of every Filament panel page.
 * Polls the cached health snapshot on the same 60s cadence as the
 * dashboard cards and listens for a `system-health-updated` event so
 * a dashboard refresh updates both surfaces in the same tick.
 *
 * Reads cache only — never triggers fresh probes — so it's free on
 * every poll. The dashboard's refresh action is the authoritative
 * writer (clears cache + re-runs every check) and then broadcasts.
 */
class SystemStatusBar extends Component
{
    public string $cls = '';

    public string $title = '';

    public function mount(): void
    {
        $this->load();
    }

    #[On('system-health-updated')]
    public function load(): void
    {
        $checks = Cache::get('system_health:checks');

        if (! is_array($checks) || empty($checks)) {
            // Nothing cached yet — run the probe suite inline so the
            // bar shows a real state on first paint instead of
            // staying hidden until someone visits the dashboard.
            $checks = app(SystemHealthService::class)->runAll(useCache: true);
        }

        // Use effectiveStatus() so acknowledged checks count as
        // OK for the top-bar rollup — matches what
        // SystemHealthService::summarize() does for the summary
        // label. The raw status is still visible on the dashboard
        // cards themselves.
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

        [$this->cls, $this->title] = match (true) {
            $down > 0 => ['is-down', "{$down} component(s) with problems".($warn ? ", {$warn} degraded" : '')],
            $warn > 0 => ['is-warn', "{$warn} component(s) degraded"],
            default => ['is-ok', 'All systems OK'],
        };

        // Broadcast the current state to the client. The sidebar
        // nav-item sync script in panel-styles.blade.php listens
        // for this event and patches the Status entry's badge in
        // place — so the sidebar, the top-of-page status bar, and
        // the dashboard cards all stay in lockstep without
        // requiring a full page reload. Passed as browser event
        // (not ->to()/->self()) so any listener on the page can
        // subscribe via `window.addEventListener`.
        //
        // Labels are in lockstep with HealthCheck::statusLabel()
        // and Dashboard::getNavigationBadge — one vocabulary
        // across every surface: Operational / Degraded / Outage.
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
        $this->dispatch('orbital-status-update', badge: $badge, color: $color);
    }

    public function render()
    {
        return view('livewire.system-status-bar');
    }
}
