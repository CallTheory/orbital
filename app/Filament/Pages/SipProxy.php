<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\Telephony\KamailioService;
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
    }

    /**
     * Current state of the first (and in Phase 0, only) backend.
     * Used by the blade template to show/hide contextual actions.
     */
    public function getCurrentBackendState(): string
    {
        return $this->dispatchers[0]['state'] ?? 'unknown';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('activate')
                ->label('Activate')
                ->icon('heroicon-o-play')
                ->color('success')
                ->visible(fn (): bool => in_array($this->getCurrentBackendState(), ['draining', 'disabled'], true))
                ->action(function () {
                    $service = app(KamailioService::class);
                    $ok = $service->setBackendState(1, 'asterisk:5060', 'active');
                    Notification::make()
                        ->title($ok ? 'Backend activated' : 'Failed to activate backend')
                        ->{$ok ? 'success' : 'danger'}()
                        ->send();
                    $this->refreshState();
                }),

            Action::make('drain')
                ->label('Drain')
                ->icon('heroicon-o-pause')
                ->color('warning')
                ->visible(fn (): bool => $this->getCurrentBackendState() === 'active')
                ->action(function () {
                    $service = app(KamailioService::class);
                    $ok = $service->setBackendState(1, 'asterisk:5060', 'drain');
                    Notification::make()
                        ->title($ok ? 'Draining — no new calls' : 'Failed to start drain')
                        ->body($ok ? 'Existing calls will complete naturally. Watch the Active Calls counter reach zero before restarting Asterisk.' : '')
                        ->{$ok ? 'warning' : 'danger'}()
                        ->send();
                    $this->refreshState();
                }),

            Action::make('disable')
                ->label('Disable')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Disable backend')
                ->modalDescription('This immediately stops sending ALL calls to this backend — including new calls. Existing calls may be affected if Asterisk is restarted while they\'re in progress. Use Drain instead for a graceful shutdown.')
                ->action(function () {
                    $service = app(KamailioService::class);
                    $ok = $service->setBackendState(1, 'asterisk:5060', 'disable');
                    Notification::make()
                        ->title($ok ? 'Backend disabled' : 'Failed to disable backend')
                        ->{$ok ? 'danger' : 'danger'}()
                        ->send();
                    $this->refreshState();
                }),
        ];
    }
}
