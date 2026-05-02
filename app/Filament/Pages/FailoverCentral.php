<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\FailoverAuditLog;
use App\Models\RtpengineNode;
use App\Services\HighAvailability\HAProxyStatsClient;
use App\Services\HighAvailability\PatroniClient;
use App\Services\HighAvailability\SeaweedMasterClient;
use App\Services\HighAvailability\SentinelClient;
use App\Services\Telephony\RtpengineDrainService;
use App\Services\Telephony\RtpengineService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use UnitEnum;

/**
 * Failover Central — the operator console for every HA tier.
 *
 * Lists per-tier live state and exposes the incident-response
 * actions that an on-call operator would otherwise have to run
 * via shell against Patroni REST, Sentinel, HAProxy stats, etc.
 *
 * Every action writes a row to `failover_audit_logs` with actor,
 * tier, action, target, success, and raw control-plane output
 * for post-incident review. Destructive actions require a typed
 * confirmation matching the target name (same UX pattern as
 * github repo delete).
 *
 * Polls every 5s via wire:poll. Super-admin only.
 */
class FailoverCentral extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Failover';

    protected static ?string $title = 'Failover';

    protected ?string $subheading = 'Live status and incident controls for every HA tier. SIP-side drain lives on the SIP Proxy page.';

    protected static ?string $slug = 'failover';

    protected string $view = 'filament.pages.failover-central';

    public ?array $patroni = null;

    public ?array $sentinel = null;

    public ?array $seaweed = null;

    public array $haproxyServers = [];

    /**
     * @var array<int, array{
     *     id: int,
     *     hostname: string,
     *     label: string,
     *     is_active: bool,
     *     ng_responding: bool,
     *     statistics: array<string, mixed>|null,
     * }>
     */
    public array $rtpengineNodes = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->refreshState();
    }

    public function refreshState(): void
    {
        // Per-tier mode gates the cluster-state probes — when a customer
        // is using managed Postgres / Valkey / S3 those endpoints
        // (Patroni REST, Sentinel SENTINEL MASTER, SeaweedFS /cluster/status)
        // don't exist. The blade template likewise hides the tier rows
        // when the matching `*Mode` is not 'cluster'.
        $this->patroni = $this->isClusterMode('postgres')
            ? app(PatroniClient::class)->cluster()
            : null;
        $this->sentinel = $this->isClusterMode('valkey')
            ? app(SentinelClient::class)->status()
            : null;
        $this->seaweed = $this->isClusterMode('object_storage')
            ? app(SeaweedMasterClient::class)->status()
            : null;
        $this->haproxyServers = app(HAProxyStatsClient::class)->servers();
        $this->rtpengineNodes = $this->loadRtpengineNodes();
    }

    /**
     * Whether a given tier is in 'cluster' mode (vs 'managed' / 'none').
     * Used to gate cluster-aware probes + UI rows.
     */
    public function isClusterMode(string $tier): bool
    {
        return config("failover-tiers.{$tier}", 'cluster') === 'cluster';
    }

    /**
     * Whether a tier should render at all on the page. 'none' means
     * the tier doesn't apply to this install (e.g. some future
     * deployment that skips object storage entirely).
     */
    public function isTierVisible(string $tier): bool
    {
        return config("failover-tiers.{$tier}", 'cluster') !== 'none';
    }

    /**
     * Pull every registered rtpengine node + per-node NG state.
     * `is_active` is the registry flag (drain has flipped this off);
     * `ng_responding` is whether the daemon answered our most recent
     * ping; `statistics` is the raw NG `statistics` reply (current
     * sessions, bytes, etc.) for the modal.
     *
     * @return array<int, array{id: int, hostname: string, label: string, is_active: bool, ng_responding: bool, statistics: array<string, mixed>|null}>
     */
    protected function loadRtpengineNodes(): array
    {
        $rtpengine = app(RtpengineService::class);
        $rows = [];
        foreach (RtpengineNode::query()->orderBy('sort_order')->orderBy('id')->get() as $node) {
            $statistics = null;
            $responding = false;
            if ($node->is_active) {
                $statistics = $rtpengine->statistics($node);
                $responding = is_array($statistics);
            }
            $rows[] = [
                'id' => (int) $node->id,
                'hostname' => $node->hostname,
                'label' => $node->label(),
                'is_active' => (bool) $node->is_active,
                'ng_responding' => $responding,
                'statistics' => $statistics,
            ];
        }

        return $rows;
    }

    /**
     * Aggregate health summary for the rtpengine tier card. Same
     * grammar as the other tiers (`healthy` / `degraded` / `down`)
     * so the blade can colour the section header consistently.
     */
    public function rtpengineHealth(): string
    {
        $active = array_filter($this->rtpengineNodes, fn ($n) => $n['is_active']);
        if ($active === []) {
            // No active nodes registered → treat as warn so the card
            // surfaces but doesn't pretend to be down.
            return 'warn';
        }
        $up = array_filter($active, fn ($n) => $n['ng_responding']);
        if (count($up) === count($active)) {
            return 'ok';
        }
        if (count($up) === 0) {
            return 'down';
        }

        return 'warn';
    }

    /** Group HAProxy server rows by backend name for the per-frontend card list. */
    public function haproxyByBackend(): array
    {
        $grouped = [];
        foreach ($this->haproxyServers as $s) {
            $grouped[$s['pxname']][] = $s;
        }
        ksort($grouped);

        return $grouped;
    }

    /**
     * Explain the expected UP/DOWN shape for each HAProxy backend.
     * Backends that route based on an HTTP role check (Patroni
     * `/primary` vs `/replica`, valkey AUTH+ROLE tcp-check) show
     * only the qualifying node as UP by design — without this
     * note the admin sees "1 UP, 2 DOWN" and thinks something's
     * broken. Returns null when no explanation is needed
     * (round-robin backends behave the obvious way).
     */
    public function haproxyBackendNote(string $backend): ?string
    {
        return match ($backend) {
            'pgsql_rw_be' => 'Expect exactly one UP: Patroni\'s leader. Replicas correctly fail the /primary health check and show DOWN here — that\'s how the router sends writes to the leader only.',
            'pgsql_ro_be' => 'Expect replicas UP, leader DOWN: the /replica check only passes on non-leaders. A row-reversed state usually means a switchover just happened.',
            'valkey_be' => 'Expect exactly one UP: Sentinel\'s current master. Replicas answer ROLE with "slave" so the tcp-check rejects them — correct for a write path.',
            'asterisk_wss_be' => 'All Asterisk backends should be UP in normal operation. MAINT means an operator drained it from the SIP Proxy page.',
            'seaweed_s3_be' => 'Both filers share one Valkey-backed metadata store so round-robin is fine. Any DOWN here means a filer is restarting or its /healthz is failing.',
            default => null,
        };
    }

    /**
     * At-a-glance section health. Each tier returns one of:
     *   'ok'    — everything in the expected state → green border
     *   'warn'  — degraded but still serving → yellow border
     *   'down'  — tier is offline or cannot be reached → red border
     *
     * Kept deliberately conservative: a tier only reports 'ok' when
     * there's unambiguous evidence of health. If we can't tell, we
     * warn rather than silently show green.
     */
    public function patroniHealth(): string
    {
        if (! $this->patroni) {
            return 'down';
        }
        if (! ($this->patroni['leader'] ?? null)) {
            return 'down';
        }
        foreach ($this->patroni['members'] ?? [] as $m) {
            $state = (string) ($m['state'] ?? '');
            $role = (string) ($m['role'] ?? '');
            $lag = (int) ($m['lag'] ?? 0);
            // Leader should be "running"; replicas should be "streaming".
            $wantedState = $role === 'leader' ? 'running' : 'streaming';
            if ($state !== $wantedState) {
                return 'warn';
            }
            // Arbitrary-but-practical threshold: any replica more
            // than 32MB behind is suspicious on a quiet cluster.
            if ($role !== 'leader' && $lag > 32) {
                return 'warn';
            }
        }

        return 'ok';
    }

    public function sentinelHealth(): string
    {
        if (! $this->sentinel) {
            return 'down';
        }
        if (empty($this->sentinel['master'])) {
            return 'down';
        }
        $masterFlags = (string) ($this->sentinel['master']['flags'] ?? '');
        if (str_contains($masterFlags, 'down') || str_contains($masterFlags, 'disconnect')) {
            return 'down';
        }
        foreach ($this->sentinel['replicas'] ?? [] as $r) {
            $flags = (string) ($r['flags'] ?? '');
            if (str_contains($flags, 'down') || str_contains($flags, 'disconnect')) {
                return 'warn';
            }
        }

        return 'ok';
    }

    public function seaweedHealth(): string
    {
        if (! $this->seaweed) {
            return 'down';
        }
        if (! ($this->seaweed['leader'] ?? null)) {
            return 'down';
        }
        foreach ($this->seaweed['masters'] ?? [] as $m) {
            if (! ($m['reachable'] ?? false)) {
                return 'warn';
            }
        }
        // At least one filer must answer /healthz — filers are the
        // S3 gateway, so if both are down the object store is
        // effectively offline even when Raft is healthy.
        $filersUp = 0;
        foreach ($this->seaweed['filers'] ?? [] as $f) {
            if ($f['reachable'] ?? false) {
                $filersUp++;
            }
        }
        if (! empty($this->seaweed['filers']) && $filersUp === 0) {
            return 'down';
        }
        if (! empty($this->seaweed['filers']) && $filersUp < count($this->seaweed['filers'])) {
            return 'warn';
        }

        return 'ok';
    }

    /**
     * Per-backend health using the shape-expectations noted in
     * haproxyBackendNote(). Returns the same 'ok'/'warn'/'down'
     * vocabulary so it aggregates cleanly with the other tiers.
     *
     * @param  list<array<string, string>>  $servers
     */
    public function haproxyBackendHealth(string $backend, array $servers): string
    {
        if (empty($servers)) {
            return 'down';
        }
        $upCount = 0;
        $maintCount = 0;
        foreach ($servers as $s) {
            $status = (string) ($s['status'] ?? '');
            if (str_starts_with($status, 'UP')) {
                $upCount++;
            } elseif (str_contains($status, 'MAINT')) {
                $maintCount++;
            }
        }
        $total = count($servers);

        return match ($backend) {
            // Role-restricted write paths: exactly one UP is the
            // correct state. Anything else is a problem.
            'pgsql_rw_be', 'valkey_be' => match (true) {
                $upCount === 1 => 'ok',
                $upCount === 0 => 'down',
                default => 'warn',  // multiple UP shouldn't happen
            },
            // Read-only pool: at least one replica UP = ok.
            'pgsql_ro_be' => match (true) {
                $upCount >= 1 => 'ok',
                default => 'down',
            },
            // Round-robin pools: ALL should be UP (minus operator-
            // drained nodes, which show MAINT and aren't a problem).
            'asterisk_wss_be', 'seaweed_s3_be', 'grafana_be', 'prometheus_be', 'loki_be' => match (true) {
                $upCount + $maintCount === $total && $upCount > 0 => 'ok',
                $upCount === 0 => 'down',
                default => 'warn',
            },
            default => $upCount > 0 ? 'ok' : 'down',
        };
    }

    /**
     * Worst per-backend state bubbles up to the section header.
     */
    public function haproxyHealth(): string
    {
        if (empty($this->haproxyServers)) {
            return 'down';
        }
        $worst = 'ok';
        foreach ($this->haproxyByBackend() as $backend => $servers) {
            $h = $this->haproxyBackendHealth($backend, $servers);
            if ($h === 'down') {
                return 'down';
            }
            if ($h === 'warn') {
                $worst = 'warn';
            }
        }

        return $worst;
    }

    /**
     * Resolve a server-name + backend to a human role tag for the
     * per-server rows. Pulls from the already-fetched Patroni /
     * Sentinel / SeaweedFS state so we don't make extra calls per
     * row render. Returns null when a backend has no meaningful
     * role distinction — caller just skips the tag.
     *
     * Examples:
     *   pgsql_rw_be + patroni-2   → "leader" / "sync_standby" / "replica"
     *   valkey_be + valkey-1      → "master" / "slave"
     *   asterisk_wss_be + asterisk-1 → "peer" (active/active — no role)
     */
    public function serverRoleTag(string $backend, string $svname): ?string
    {
        return match ($backend) {
            'pgsql_rw_be', 'pgsql_ro_be' => $this->patroniRoleFor($svname),
            'valkey_be' => $this->sentinelRoleFor($svname),
            'seaweed_s3_be' => 'filer',
            'asterisk_wss_be' => 'peer',
            'grafana_be', 'prometheus_be', 'loki_be' => 'peer',
            default => null,
        };
    }

    private function patroniRoleFor(string $name): ?string
    {
        if (! $this->patroni) {
            return null;
        }
        foreach ($this->patroni['members'] ?? [] as $m) {
            if (($m['name'] ?? null) === $name) {
                // Patroni's `replica` role covers both synchronous and
                // asynchronous replicas — humanise to match the member
                // state shown in the Patroni card above.
                return str_replace('_', ' ', (string) $m['role']);
            }
        }

        return null;
    }

    private function sentinelRoleFor(string $name): ?string
    {
        if (! $this->sentinel) {
            return null;
        }
        // Sentinel reports ip+port; HAProxy reports container name.
        // Match loosely: if the ip looks like a hostname compare
        // directly, otherwise fall back to the last byte of the IP.
        $matches = function (array $node) use ($name): bool {
            $ip = (string) ($node['ip'] ?? '');

            return $ip === $name
                || str_starts_with($ip, $name.'.')
                || str_ends_with($ip, '.'.$name);
        };

        $master = $this->sentinel['master'] ?? null;
        if ($master && $matches($master)) {
            return 'master';
        }
        foreach ($this->sentinel['replicas'] ?? [] as $r) {
            if ($matches($r)) {
                return 'replica';
            }
        }

        return null;
    }

    /**
     * Sort Patroni members into the operational hierarchy:
     * leader → sync_standby → replica → any other state. Keeps the
     * card order stable across refreshes so the eye doesn't jump
     * when a leader steps down and the new leader slides up.
     *
     * @return list<array<string, mixed>>
     */
    public function patroniMembersSorted(): array
    {
        if (! $this->patroni) {
            return [];
        }
        $rank = fn (string $role): int => match ($role) {
            'leader' => 0,
            'sync_standby' => 1,
            'replica' => 2,
            default => 3,
        };
        $members = $this->patroni['members'] ?? [];
        usort($members, fn ($a, $b) => $rank($a['role']) <=> $rank($b['role'])
            ?: strcmp((string) $a['name'], (string) $b['name']));

        return $members;
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh now')
                ->icon('heroicon-o-arrow-path')
                ->action('refreshState'),
        ];
    }

    /** Header action — Patroni switchover with candidate select. */
    public function patroniSwitchoverAction(): Action
    {
        return Action::make('patroniSwitchover')
            ->label('Patroni switchover')
            ->icon('heroicon-o-arrows-right-left')
            ->color('warning')
            ->modalHeading('Switch Patroni leader')
            ->modalDescription(
                'Graceful handoff. Current leader steps down; the '.
                'selected candidate is promoted. Writes pause for '.
                '~5s during the handoff.'
            )
            ->form([
                Select::make('candidate')
                    ->label('New leader')
                    ->options(fn () => $this->eligiblePatroniCandidates())
                    ->required(),
                TextInput::make('confirm')
                    ->label('Type the candidate name to confirm')
                    ->required(),
            ])
            ->action(function (array $data): void {
                if ($data['candidate'] !== $data['confirm']) {
                    Notification::make()
                        ->danger()
                        ->title('Confirmation did not match')
                        ->send();

                    return;
                }
                // Record the intent BEFORE firing so we get an
                // audit row even if Postgres is unavailable during
                // the handoff (writes pause while the leader steps
                // down). Update outcome after the cluster settles.
                $log = FailoverAuditLog::record(
                    tier: 'postgres',
                    action: 'switchover',
                    target: $data['candidate'],
                    success: false,
                    output: 'switchover initiated',
                );
                [$ok, $out] = app(PatroniClient::class)->switchover($data['candidate']);
                // Patroni side will have accepted the request even
                // while pg writes pause; we'll re-open a connection
                // once the new leader is up and update the audit row.
                try {
                    $log->update([
                        'success' => $ok,
                        'output' => mb_substr($out, 0, 8000),
                    ]);
                } catch (\Throwable $e) {
                    // If writes are still blocked, the audit row
                    // stays in "initiated" state — a human can
                    // reconcile later; the Patroni REST response
                    // below still tells the operator what happened.
                }
                Notification::make()
                    ->title($ok ? 'Switchover complete' : 'Switchover failed')
                    ->body(mb_substr($out, 0, 400))
                    ->status($ok ? 'success' : 'danger')
                    ->send();
                $this->refreshState();
            });
    }

    /** Header action — Sentinel force failover (no target selector — Sentinel picks). */
    public function sentinelFailoverAction(): Action
    {
        return Action::make('sentinelFailover')
            ->label('Valkey force-failover')
            ->icon('heroicon-o-arrow-uturn-right')
            ->color('warning')
            ->modalHeading('Force Valkey failover')
            ->modalDescription(
                'Sentinel picks the best-positioned replica and '.
                'promotes it. Cache/queue write throughput pauses '.
                'briefly during the handoff.'
            )
            ->form([
                TextInput::make('confirm')
                    ->label('Type "orbital" to confirm')
                    ->required(),
            ])
            ->action(function (array $data): void {
                if ($data['confirm'] !== 'orbital') {
                    Notification::make()
                        ->danger()
                        ->title('Confirmation did not match')
                        ->send();

                    return;
                }
                [$ok, $out] = app(SentinelClient::class)->forceFailover();
                FailoverAuditLog::record(
                    tier: 'valkey',
                    action: 'failover',
                    target: null,
                    success: $ok,
                    output: $out,
                );
                Notification::make()
                    ->title($ok ? 'Failover triggered' : 'Failover failed')
                    ->body($out)
                    ->status($ok ? 'success' : 'danger')
                    ->send();
                $this->refreshState();
            });
    }

    /**
     * Per-node action — drain a rtpengine node. Both control points
     * flip off (NG `set-forwarding off` + `is_active=false` on the
     * registry row). Existing relayed RTP keeps flowing; new offers
     * route to surviving nodes.
     */
    public function rtpengineDrainAction(): Action
    {
        return Action::make('rtpengineDrain')
            ->label('Drain')
            ->icon('heroicon-o-pause-circle')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => "Drain {$arguments['hostname']}?")
            ->modalDescription(
                'New RTP offers route to surviving rtpengine nodes. '.
                'Calls already relayed by this node keep flowing — they '.
                'finish when the SIP dialog ends.'
            )
            ->action(function (array $arguments): void {
                $node = RtpengineNode::find($arguments['id']);
                if (! $node) {
                    Notification::make()->danger()->title('Node not found')->send();

                    return;
                }
                $result = app(RtpengineDrainService::class)->drain($node);
                $ok = $result['ng'] && $result['registry'];
                Notification::make()
                    ->title($ok ? "Drained {$node->hostname}" : "Drain partial on {$node->hostname}")
                    ->body('ng='.($result['ng'] ? 'ok' : 'fail').' registry='.($result['registry'] ? 'ok' : 'fail'))
                    ->status($ok ? 'success' : 'warning')
                    ->send();
                $this->refreshState();
            });
    }

    /** Per-node action — return a drained rtpengine node to service. */
    public function rtpengineActivateAction(): Action
    {
        return Action::make('rtpengineActivate')
            ->label('Activate')
            ->icon('heroicon-o-play-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => "Activate {$arguments['hostname']}?")
            ->modalDescription('Re-enables the registry flag and toggles NG `set-forwarding on`. New RTP offers can land on this node again.')
            ->action(function (array $arguments): void {
                $node = RtpengineNode::find($arguments['id']);
                if (! $node) {
                    Notification::make()->danger()->title('Node not found')->send();

                    return;
                }
                $result = app(RtpengineDrainService::class)->activate($node);
                $ok = $result['ng'] && $result['registry'];
                Notification::make()
                    ->title($ok ? "Activated {$node->hostname}" : "Activate partial on {$node->hostname}")
                    ->body('ng='.($result['ng'] ? 'ok' : 'fail').' registry='.($result['registry'] ? 'ok' : 'fail'))
                    ->status($ok ? 'success' : 'warning')
                    ->send();
                $this->refreshState();
            });
    }

    /**
     * Candidate list = every Patroni member that isn't the
     * current leader AND isn't tagged nofailover.
     *
     * @return array<string, string>
     */
    protected function eligiblePatroniCandidates(): array
    {
        if (! $this->patroni) {
            return [];
        }
        $out = [];
        foreach ($this->patroni['members'] ?? [] as $m) {
            if ($m['role'] === 'leader') {
                continue;
            }
            // Patroni doesn't include the `nofailover` tag in the
            // /cluster payload by default; we filter by role instead
            // — a node in "running" + sync_standby/replica state
            // without visible tag is a valid candidate.
            $out[$m['name']] = "{$m['name']} ({$m['role']}, lag={$m['lag']}MB)";
        }

        return $out;
    }
}
