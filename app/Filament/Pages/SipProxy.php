<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\RtpengineNode;
use App\Services\Telephony\AsteriskClusterActivity;
use App\Services\Telephony\AsteriskDrainService;
use App\Services\Telephony\KamailioService;
use App\Services\Telephony\RtpengineDrainService;
use App\Services\Telephony\RtpengineService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use UnitEnum;

/**
 * Admin page for monitoring and controlling the Kamailio SIP proxy.
 *
 * Shows real-time proxy health, active call count, and the state of
 * each dispatcher backend (currently just one Asterisk instance for
 * Phase 0). The operator can drain a backend (no new calls, existing
 * calls complete naturally), activate it back, or disable it hard
 * (immediately stops routing).
 *
 * Polling every 5s via wire:poll gives near-real-time "X active calls
 * remaining" during drain without requiring Reverb/websocket
 * complexity for a page that typically has 1-2 viewers at a time.
 *
 * The page hides itself from the nav when `telephony.kamailio.enabled`
 * is false (no Kamailio container running) so installs that don't
 * have the proxy don't see a dead nav item.
 */
class SipProxy extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static string|UnitEnum|null $navigationGroup = 'Telephony';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'SIP Proxy';

    protected static ?string $title = 'SIP Proxy';

    protected ?string $subheading = 'Kamailio signaling proxy for SIP trunk traffic.';

    protected static ?string $slug = 'sip-proxy';

    protected string $view = 'filament.pages.sip-proxy';

    public bool $healthy = false;

    public string $uptime = 'N/A';

    /** @var array<int, array<string, mixed>> */
    public array $dispatchers = [];

    public int $activeDialogs = 0;

    /**
     * Per-Asterisk activity map, keyed by short hostname.
     * Drives the "Drained and safe to restart" vs "Still has
     * traffic" labeling on each backend card.
     *
     *   channels       — live channels on THIS node (AMI)
     *   registrations  — dynamic REGISTERs where reg_server =
     *                    this node's systemname (ps_contacts)
     *
     * @var array<string, array{channels: int, registrations: int, reachable: bool}>
     */
    public array $activity = [];

    /**
     * Per-rtpengine state map. Lets the SIP Proxy page render the
     * media-relay tier alongside the Asterisk dispatchers, with the
     * same drain/activate UX. Same shape as `$activity` but for the
     * NG control surface.
     *
     * @var array<int, array{
     *     id: int,
     *     hostname: string,
     *     label: string,
     *     is_active: bool,
     *     ng_responding: bool,
     *     active_sessions: int,
     * }>
     */
    public array $rtpengineNodes = [];

    public static function canAccess(): bool
    {
        if (! config('telephony.kamailio.enabled')) {
            return false;
        }

        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->refreshState();
    }

    public function refreshState(): void
    {
        $service = app(KamailioService::class);

        $this->healthy = $service->isHealthy();

        $uptime = $service->getUptime();
        if ($uptime !== null) {
            $seconds = $uptime['uptime'];
            $days = intdiv($seconds, 86400);
            $hours = intdiv($seconds % 86400, 3600);
            $minutes = intdiv($seconds % 3600, 60);
            $this->uptime = ($days > 0 ? "{$days}d " : '')."{$hours}h {$minutes}m";
        } else {
            $this->uptime = 'N/A';
        }

        $this->dispatchers = $service->listDispatchers();
        $this->activeDialogs = $service->getActiveDialogCount();

        // Per-Asterisk activity: channels + contacts. A drained
        // backend is only SAFE to restart when both reach zero —
        // channels = live calls terminating there, contacts = live
        // softphone/hardware registrations. AMI query is cheap
        // (two commands over a short-lived socket per node) so
        // the 5s poll doesn't stress the Asterisks.
        $this->activity = app(AsteriskClusterActivity::class)->all();
        $this->rtpengineNodes = $this->loadRtpengineNodes();
    }

    /**
     * @return array<int, array{id: int, hostname: string, label: string, is_active: bool, ng_responding: bool, active_sessions: int}>
     */
    protected function loadRtpengineNodes(): array
    {
        $rtpengine = app(RtpengineService::class);
        $rows = [];
        foreach (RtpengineNode::query()->orderBy('sort_order')->orderBy('id')->get() as $node) {
            $sessions = 0;
            $responding = false;
            if ($node->is_active) {
                $stats = $rtpengine->statistics($node);
                if (is_array($stats)) {
                    $responding = true;
                    $sessions = (int) ($stats['statistics']['currentstatistics']['sessionsown']
                        ?? $stats['currentstatistics']['sessionsown']
                        ?? 0);
                }
            }
            $rows[] = [
                'id' => (int) $node->id,
                'hostname' => $node->hostname,
                'label' => $node->label(),
                'is_active' => (bool) $node->is_active,
                'ng_responding' => $responding,
                'active_sessions' => $sessions,
            ];
        }

        return $rows;
    }

    public function drainRtpengineAction(): Action
    {
        return Action::make('drainRtpengine')
            ->label('Drain')
            ->icon('heroicon-o-pause')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => "Drain {$arguments['hostname']}?")
            ->modalDescription(
                'Stops new RTP offers landing on this rtpengine node — '.
                'the registry flag flips off and NG `set-forwarding off` '.
                'rejects fresh dialogs. Calls already relayed by this '.
                'node finish naturally.',
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
                    ->title($ok ? "Draining {$node->hostname}" : "Partial drain of {$node->hostname}")
                    ->body('NG: '.($result['ng'] ? 'ok' : 'FAILED').' · Registry: '.($result['registry'] ? 'ok' : 'FAILED'))
                    ->{$ok ? 'warning' : 'danger'}()
                    ->send();
                $this->refreshState();
            });
    }

    public function activateRtpengineAction(): Action
    {
        return Action::make('activateRtpengine')
            ->label('Activate')
            ->icon('heroicon-o-play')
            ->color('success')
            ->action(function (array $arguments): void {
                $node = RtpengineNode::find($arguments['id']);
                if (! $node) {
                    Notification::make()->danger()->title('Node not found')->send();

                    return;
                }
                $result = app(RtpengineDrainService::class)->activate($node);
                $ok = $result['ng'] && $result['registry'];
                Notification::make()
                    ->title($ok ? "Activated {$node->hostname}" : "Partial activate of {$node->hostname}")
                    ->body('NG: '.($result['ng'] ? 'ok' : 'FAILED').' · Registry: '.($result['registry'] ? 'ok' : 'FAILED'))
                    ->{$ok ? 'success' : 'warning'}()
                    ->send();
                $this->refreshState();
            });
    }

    /**
     * Activate a drained or disabled backend. The view invokes
     * this action with `->arguments(['address' => ..., 'setId' => ...])`
     * so Filament/Livewire can round-trip the target through the
     * click handler. A per-row closure won't work — Filament can't
     * serialize closures for the action lifecycle, so we register
     * one static action per kind and pass the row data as arguments.
     */
    public function activateBackendAction(): Action
    {
        return Action::make('activateBackend')
            ->label('Activate')
            ->icon('heroicon-o-play')
            ->color('success')
            ->action(function (array $arguments): void {
                $result = app(AsteriskDrainService::class)->activate(
                    (string) $arguments['backend'],
                );
                $ok = $result['kamailio'] && $result['haproxy'];
                Notification::make()
                    ->title($ok
                        ? "Activated {$arguments['backend']}"
                        : "Partially activated {$arguments['backend']}")
                    ->body($ok
                        ? 'Kamailio dispatcher + HAProxy WSS both online.'
                        : $this->summarize($result))
                    ->{$ok ? 'success' : 'warning'}()
                    ->send();
                $this->refreshState();
            });
    }

    public function drainBackendAction(): Action
    {
        return Action::make('drainBackend')
            ->label('Drain')
            ->icon('heroicon-o-pause')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => "Drain {$arguments['backend']}")
            ->modalDescription(
                'Stops NEW traffic to this Asterisk on both paths: '.
                'inbound SIP trunk calls (Kamailio) and operator '.
                'softphone WSS connections (HAProxy). Existing calls '.
                'and sessions continue until their dialogs end '.
                'naturally. Operators currently on this node will '.
                'be notified to finish their call, go unavailable, '.
                'and log out/in to migrate. The backend stays drained '.
                'until you click Activate.',
            )
            ->action(function (array $arguments): void {
                $result = app(AsteriskDrainService::class)->drain(
                    (string) $arguments['backend'],
                );
                $ok = $result['kamailio'] && $result['haproxy'];
                Notification::make()
                    ->title($ok
                        ? "Draining {$arguments['backend']}"
                        : "Partial drain of {$arguments['backend']}")
                    ->body($ok
                        ? 'SIP trunks + operator WSS both pulled '.
                          'from rotation. Wait for active traffic '.
                          'to reach zero before restarting.'
                        : $this->summarize($result))
                    ->{$ok ? 'warning' : 'danger'}()
                    ->send();
                $this->refreshState();
            });
    }

    public function disableBackendAction(): Action
    {
        return Action::make('disableBackend')
            ->label('Disable')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => "Hard-disable {$arguments['backend']}")
            ->modalDescription(
                'Immediately stops ALL traffic to this backend AND '.
                'stops Kamailio probing. Use Drain instead for planned '.
                'maintenance — Disable leaves you with no visibility '.
                'into whether the node is still alive.',
            )
            ->action(function (array $arguments): void {
                $result = app(AsteriskDrainService::class)->disable(
                    (string) $arguments['backend'],
                );
                $ok = $result['kamailio'] && $result['haproxy'];
                Notification::make()
                    ->title($ok
                        ? "Disabled {$arguments['backend']}"
                        : "Partial disable of {$arguments['backend']}")
                    ->body($ok ? null : $this->summarize($result))
                    ->{$ok ? 'danger' : 'warning'}()
                    ->send();
                $this->refreshState();
            });
    }

    /**
     * Turn the {kamailio: bool, haproxy: bool} tuple into a
     * human line for notification bodies on partial failures.
     *
     * @param  array{kamailio: bool, haproxy: bool}  $r
     */
    protected function summarize(array $r): string
    {
        $parts = [];
        $parts[] = 'Kamailio: '.($r['kamailio'] ? 'ok' : 'FAILED');
        $parts[] = 'HAProxy: '.($r['haproxy'] ? 'ok' : 'FAILED');

        return implode(' · ', $parts);
    }
}
