<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Models\FailoverAuditLog;
use App\Models\RtpengineNode;
use Illuminate\Support\Facades\Log;

/**
 * Drain coordinator for rtpengine media-relay nodes.
 *
 * Two control points to manipulate per node:
 *   1. The NG `set-forwarding` toggle on the node itself —
 *      stops the relay loop from accepting new offers.
 *   2. The `is_active` flag on the `rtpengine_nodes` registry
 *      row — Kamailio's per-call selection skips inactive nodes
 *      for any new dialog so existing calls stay routed correctly.
 *
 * Mirrors {@see AsteriskDrainService}'s public surface — same
 * `drain` / `activate` / `disable` verbs, return shape is the
 * pair of bools per control point. Each verb writes a
 * `FailoverAuditLog` row tagged `tier='rtpengine'`.
 */
class RtpengineDrainService
{
    public function __construct(
        protected RtpengineService $rtpengine,
    ) {}

    /**
     * Drain a single rtpengine node — flip both control points off
     * so new offers route elsewhere; in-flight calls finish naturally.
     *
     * @return array{ng: bool, registry: bool}
     */
    public function drain(RtpengineNode $node): array
    {
        Log::info('rtpengine-drain: drain() called', ['node' => $node->hostname]);

        $ngOk = $this->rtpengine->setForwarding($node, false);
        $registryOk = $node->update(['is_active' => false]);

        FailoverAuditLog::record(
            tier: 'rtpengine',
            action: 'drain',
            target: $node->hostname,
            success: $ngOk && $registryOk,
            output: "ng={$this->bool($ngOk)} registry={$this->bool($registryOk)}",
        );

        return ['ng' => $ngOk, 'registry' => $registryOk];
    }

    /**
     * Return a drained node to service.
     *
     * @return array{ng: bool, registry: bool}
     */
    public function activate(RtpengineNode $node): array
    {
        Log::info('rtpengine-drain: activate() called', ['node' => $node->hostname]);

        $ngOk = $this->rtpengine->setForwarding($node, true);
        $registryOk = $node->update(['is_active' => true]);

        FailoverAuditLog::record(
            tier: 'rtpengine',
            action: 'activate',
            target: $node->hostname,
            success: $ngOk && $registryOk,
            output: "ng={$this->bool($ngOk)} registry={$this->bool($registryOk)}",
        );

        return ['ng' => $ngOk, 'registry' => $registryOk];
    }

    /**
     * Hard-disable a node. Same effect as drain today (NG forwarding
     * off + registry flag off); kept as a separate verb to mirror
     * the Asterisk pattern and to give the audit log a distinct
     * action label. If/when rtpengine grows a probe-disable
     * primitive (the way Kamailio's `dispatcher.set_state disable`
     * does), wire it here.
     *
     * @return array{ng: bool, registry: bool}
     */
    public function disable(RtpengineNode $node): array
    {
        Log::info('rtpengine-drain: disable() called', ['node' => $node->hostname]);

        $ngOk = $this->rtpengine->setForwarding($node, false);
        $registryOk = $node->update(['is_active' => false]);

        FailoverAuditLog::record(
            tier: 'rtpengine',
            action: 'disable',
            target: $node->hostname,
            success: $ngOk && $registryOk,
            output: "ng={$this->bool($ngOk)} registry={$this->bool($registryOk)}",
        );

        return ['ng' => $ngOk, 'registry' => $registryOk];
    }

    private function bool(bool $b): string
    {
        return $b ? 'ok' : 'fail';
    }
}
