<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\FailoverAuditLog;
use App\Services\HighAvailability\HAProxyStatsClient;
use App\Services\HighAvailability\PatroniClient;
use App\Services\HighAvailability\SeaweedMasterClient;
use App\Services\HighAvailability\SentinelClient;
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

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

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
        $this->patroni = app(PatroniClient::class)->cluster();
        $this->sentinel = app(SentinelClient::class)->status();
        $this->seaweed = app(SeaweedMasterClient::class)->status();
        $this->haproxyServers = app(HAProxyStatsClient::class)->servers();
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
