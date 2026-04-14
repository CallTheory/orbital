<?php

declare(strict_types=1);

namespace App\Services\Bootstrap\Bootstrappers;

use App\Models\Extension;
use App\Services\Bootstrap\Bootstrapper;
use App\Services\Bootstrap\BootstrapReport;
use App\Services\Bootstrap\BootstrapStatus;
use App\Services\Telephony\LiveKitConfigService;
use Throwable;

/**
 * Publishes LiveKit dispatch rules from the database so inbound calls
 * hit the right agent persona's room. The actual publishing logic
 * lives in LiveKitConfigService; this bootstrapper is the idempotent
 * wrapper that reports status and handles failures.
 *
 * v1 doesn't push dispatch rules to the LiveKit server (LiveKitConfigService
 * currently only builds the rule array — pushing to LiveKit via the API
 * lands when we wire SIP trunks end-to-end). This bootstrapper reports
 * reachable + rule count so operators can see the intended state.
 */
class LiveKitBootstrapper implements Bootstrapper
{
    public function key(): string
    {
        return 'livekit';
    }

    public function name(): string
    {
        return 'LiveKit dispatch';
    }

    public function description(): string
    {
        return 'Verifies LiveKit is reachable and builds dispatch rules from the active AI agent extensions.';
    }

    public function icon(): string
    {
        return 'heroicon-o-signal';
    }

    public function isOptional(): bool
    {
        return false;
    }

    public function status(): BootstrapReport
    {
        $svc = app(LiveKitConfigService::class);

        $reachable = false;
        try {
            $reachable = $svc->healthCheck();
        } catch (Throwable) {
            $reachable = false;
        }

        $agentCount = Extension::withoutGlobalScopes()
            ->where('type', 'ai_agent')
            ->where('is_active', true)
            ->count();

        $dispatchCount = 0;
        try {
            $dispatchCount = count($svc->buildDispatchRules());
        } catch (Throwable) {
            $dispatchCount = 0;
        }

        return new BootstrapReport(
            status: $reachable ? BootstrapStatus::Installed : BootstrapStatus::Missing,
            message: $reachable
                ? "LiveKit reachable. {$agentCount} active AI agents, {$dispatchCount} dispatch rules built."
                : 'LiveKit is not reachable — check the livekit container.',
            steps: [
                ['label' => 'LiveKit reachable', 'ok' => $reachable, 'detail' => null],
                ['label' => 'AI agents configured', 'ok' => $agentCount > 0, 'detail' => (string) $agentCount],
                ['label' => 'Dispatch rules built', 'ok' => $dispatchCount > 0, 'detail' => (string) $dispatchCount],
            ],
        );
    }

    public function install(): BootstrapReport
    {
        // Same as status for now — building the dispatch rules is side-effect-free.
        // When LiveKitConfigService grows a pushDispatchRules() call (pushing to
        // the LiveKit server via its API), this method will call that after
        // buildDispatchRules() returns.
        return $this->status();
    }
}
