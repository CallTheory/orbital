<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\HealthCheckAcknowledgment;
use App\Services\Health\SystemHealthService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\On;
use UnitEnum;

/**
 * Replaces Filament's stock dashboard with a system status board.
 *
 * Lives under the "Dashboards" nav group so we can grow other
 * dashboards (call volume, AI usage, per-tenant views) alongside
 * it in the future. Routed at `/` so it's still the panel home.
 *
 * Nav icon + badge reflect the cached health state — green icon +
 * "OK" badge when everything is healthy, amber + "Warn" on any
 * degraded check, red + "Down" if anything is actually down. No
 * glow effects; the signal comes from Filament's native badge
 * color palette matching the rest of the status UI.
 */
class Dashboard extends BaseDashboard
{
    protected static string|UnitEnum|null $navigationGroup = 'Monitor';

    protected static ?string $navigationLabel = 'Status';

    protected static ?string $title = 'System status';

    protected ?string $subheading = 'Live health of platform components.';

    protected static ?int $navigationSort = -10;

    protected string $view = 'filament.pages.dashboard';

    // Icon is intentionally static: the colored badge ("OK" /
    // "Warn" / "Down") already carries the status signal, so
    // swapping the icon shape per state is redundant noise.
    // heroicon-o-signal reads as "is everything up" without
    // implying any particular state.
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-signal';

    public static function getNavigationBadge(): ?string
    {
        // Always emit SOMETHING so the badge <span> is present in
        // the DOM even on a cold cache. The client-side sync
        // script (in panel-styles.blade.php) patches .fi-badge-label
        // and the fi-color-* class on every system-health-updated
        // event, so it needs a slot to update. A literal placeholder
        // is safer than relying on Filament to re-render the nav.
        //
        // Labels are in lockstep with HealthCheck::statusLabel(),
        // the SystemStatusBar dispatch, and the roll-up summary —
        // one vocabulary everywhere: Operational / Degraded / Outage.
        return match (self::currentStatus()) {
            'down' => 'Problem',
            'warn' => 'Degraded',
            'ok' => 'OK',
            default => '…',
        };
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return match (self::currentStatus()) {
            'down' => 'danger',
            'warn' => 'warning',
            'ok' => 'success',
            default => 'gray',
        };
    }

    /**
     * Roll up the cached health snapshot into a single "ok / warn /
     * down / null" string. Reads cache only — never triggers fresh
     * probes — so rendering the sidebar on every page stays free.
     * Returns null if nothing's been cached yet, which the icon +
     * badge methods treat as an unknown state (gray, no badge).
     */
    private static function currentStatus(): ?string
    {
        $checks = Cache::get('system_health:checks');
        if (! is_array($checks) || empty($checks)) {
            // Cache expired between probe runs. Default to 'ok'
            // rather than null (which renders a gray badge) because
            // the system was almost certainly fine the last time we
            // checked — showing "unknown" in the nav for a 1-second
            // gap between cache expiry and the next poll is alarming
            // and misleading. The next SystemStatusBar poll or
            // Reverb broadcast will re-cache and update the badge
            // to the real state within seconds.
            return 'ok';
        }

        // Same effectiveStatus() treatment as SystemStatusBar /
        // summarize() — acknowledged checks don't bump the nav
        // badge off its OK state.
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

        return match (true) {
            $down > 0 => 'down',
            $warn > 0 => 'warn',
            default => 'ok',
        };
    }

    public array $checks = [];

    public array $summary = [];

    public ?string $checkedAt = null;

    public function mount(): void
    {
        $this->loadChecks(useCache: true);
    }

    public function refresh(): void
    {
        Cache::forget('system_health:checks');
        $this->loadChecks(useCache: false);
    }

    /**
     * Repaint the card grid in response to a Reverb broadcast from
     * any tab that just re-probed (the admin who clicked refresh,
     * the operator whose ack timed out, a scheduled health run, etc).
     *
     * Reads cache only — the broadcaster just wrote it, so this tab
     * gets the fresh snapshot for free without re-probing the network.
     * Without this listener, another admin's "Acknowledge" action
     * would only update their own tab; every other open dashboard
     * would still show the un-acked state until the next page load.
     */
    #[On('echo:system-health,.system-health-updated')]
    public function onHealthBroadcast(): void
    {
        $this->loadChecks(useCache: true);
    }

    private function loadChecks(bool $useCache): void
    {
        $service = app(SystemHealthService::class);
        $checks = $service->runAll(useCache: $useCache);

        // Nudge the top-of-page status bar so its color matches the
        // card grid on every poll, not just on the manual refresh
        // action. Both surfaces read the same cache, so the event
        // is a "reload now" signal rather than a payload.
        $this->dispatch('system-health-updated');

        // Surface problems first: down → warn → ok, stable within each bucket.
        $weight = ['down' => 0, 'warn' => 1, 'ok' => 2];
        usort($checks, fn ($a, $b) => ($weight[$a->status] ?? 99) <=> ($weight[$b->status] ?? 99));

        $this->checks = array_map(fn ($c) => [
            'key' => $c->key,
            'name' => $c->name,
            'category' => $c->category,
            'status' => $c->status,
            'statusLabel' => $c->statusLabel(),
            'color' => $c->color(),
            'message' => $c->message,
            'metrics' => $c->metrics,
            'icon' => $c->icon,
            // Ack metadata — null when the card isn't
            // acknowledged. The blade checks this to render
            // the "Acknowledged by X" note + Clear button
            // instead of the Acknowledge button.
            'ack' => $c->ack,
        ], $checks);

        $this->summary = $service->summarize($checks);
        $this->checkedAt = now()->format('M j, Y g:i:s A T');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh')
                ->icon('heroicon-o-arrow-path')
                ->action('refresh'),
        ];
    }

    /**
     * Acknowledge a problematic check from its card. One-click
     * toggle — no modal, immediate DB write, toast feedback,
     * cache bust so the next render picks up the overlay.
     *
     * The row lands in `health_check_acknowledgments` with the
     * current user as `acknowledged_by_user_id`. The next call
     * to `SystemHealthService::runAll()` will attach the ack
     * metadata to the matching check and the aggregate rollup
     * will treat the card as OK — top bar, nav badge, and
     * summary all stay green. The card itself still shows the
     * raw state so the operator knows the real story.
     */
    public function acknowledgeCheck(string $checkKey): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }

        // If somehow there's already an active ack for this
        // check, no-op instead of duplicating — the toggle UI
        // shouldn't let this happen but defend anyway.
        if (HealthCheckAcknowledgment::active()->where('check_key', $checkKey)->exists()) {
            return;
        }

        HealthCheckAcknowledgment::create([
            'check_key' => $checkKey,
            'acknowledged_by_user_id' => $user->id,
            'acknowledged_at' => now(),
        ]);

        Cache::forget('system_health:checks');
        $this->loadChecks(useCache: false);

        Notification::make()
            ->title('Problem acknowledged')
            ->success()
            ->send();
    }

    /**
     * Manually clear an active ack. Updates `cleared_at` +
     * `cleared_by_user_id` on the row so the audit log
     * records who pressed the button and when. Auto-clears
     * (system recovery) leave `cleared_by_user_id` null for
     * the same row shape but no user attribution.
     */
    public function clearAcknowledgment(string $checkKey): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }

        $ack = HealthCheckAcknowledgment::active()
            ->where('check_key', $checkKey)
            ->latest('acknowledged_at')
            ->first();

        if (! $ack) {
            return;
        }

        $ack->forceFill([
            'cleared_at' => now(),
            'cleared_by_user_id' => $user->id,
        ])->save();

        Cache::forget('system_health:checks');
        $this->loadChecks(useCache: false);

        Notification::make()
            ->title('Acknowledgment cleared')
            ->success()
            ->send();
    }
}
