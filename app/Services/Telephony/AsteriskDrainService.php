<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Events\AsteriskDrainInitiated;
use App\Models\Extension;
use App\Models\User;
use App\Services\HighAvailability\HAProxyStatsClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Coordinates drain across Kamailio dispatcher (inbound SIP trunks)
 * AND HAProxy WSS backend (operator softphones). A single admin
 * click needs to stop BOTH traffic paths to a given Asterisk, not
 * just the Kamailio one — otherwise draining `asterisk-1` leaves
 * all operator softphones still hitting it.
 *
 * Drain states exposed to the UI:
 *   active    — normal rotation on both Kamailio and HAProxy WSS
 *   draining  — both control planes have pulled the node from
 *               rotation; existing traffic finishes naturally
 *   disabled  — same as draining + probes off (Kamailio only;
 *               HAProxy health check continues either way)
 *
 * The service is stateless — it just issues RPC/HTTP calls and
 * reflects whatever the control planes report. Durable drain
 * records live in the audit log (FailoverAuditLog).
 */
class AsteriskDrainService
{
    /**
     * HAProxy backend name for the Asterisk WSS frontend. Must
     * match haproxy.cfg.tmpl's backend block.
     */
    private const HAPROXY_WSS_BACKEND = 'asterisk_wss_be';

    public function __construct(
        protected KamailioService $kamailio,
        protected HAProxyStatsClient $haproxy,
    ) {}

    /**
     * Drain a specific Asterisk backend by short hostname
     * (e.g. "asterisk", "asterisk-2"). Kamailio stops dispatching
     * new SIP trunk calls; HAProxy stops routing new softphone
     * WSS connections. Existing traffic finishes naturally.
     *
     * @return array{kamailio: bool, haproxy: bool}
     */
    public function drain(string $backend): array
    {
        Log::info('asterisk-drain: drain() called', [
            'backend' => $backend,
        ]);

        $kamailioOk = $this->kamailio->setBackendState(
            1,
            "{$backend}:5060",
            'drain',
        );

        [$haproxyOk] = $this->haproxy->disableServer(
            self::HAPROXY_WSS_BACKEND,
            $backend,
        );

        // Find users whose softphone is registered on the draining
        // Asterisk and broadcast a Filament toast directly to each
        // one via their private notifications channel. This avoids
        // the public-channel + client-side filter path that gave
        // us duplicate-fire + panel-scoping headaches — Filament's
        // ->broadcast() targets a user's authenticated panel
        // sessions exclusively, and the notification stack
        // auto-subscribes whenever the user is logged in.
        $affectedEndpoints = DB::table('ps_contacts')
            ->where('reg_server', $backend)
            ->pluck('endpoint')
            ->filter()
            ->unique()
            ->values()
            ->all();

        Log::info('asterisk-drain: affected endpoints lookup', [
            'backend' => $backend,
            'affected_endpoints' => $affectedEndpoints,
            'count' => count($affectedEndpoints),
        ]);

        if (! empty($affectedEndpoints)) {
            $this->notifyAffectedOperators($backend, $affectedEndpoints);
        }

        return ['kamailio' => $kamailioOk, 'haproxy' => $haproxyOk];
    }

    /**
     * Return a drained backend to service on both control planes.
     *
     * @return array{kamailio: bool, haproxy: bool}
     */
    public function activate(string $backend): array
    {
        $kamailioOk = $this->kamailio->setBackendState(
            1,
            "{$backend}:5060",
            'active',
        );

        [$haproxyOk] = $this->haproxy->enableServer(
            self::HAPROXY_WSS_BACKEND,
            $backend,
        );

        return ['kamailio' => $kamailioOk, 'haproxy' => $haproxyOk];
    }

    /**
     * Fire a per-user AsteriskDrainInitiated event on each affected
     * operator's private channel. The operator panel has a Livewire
     * listener wired in OperatorPanelProvider that catches the
     * event and pops a Filament toast. ShouldBroadcastNow means
     * Reverb receives the push synchronously — no queue dependency.
     *
     * @param  list<string>  $affectedEndpoints  ps_contacts.endpoint values
     */
    protected function notifyAffectedOperators(string $backend, array $affectedEndpoints): void
    {
        $userIds = Extension::query()
            ->where('assignable_type', User::class)
            ->get()
            ->filter(fn (Extension $e) => in_array($e->realtimeEndpointId(), $affectedEndpoints, true))
            ->pluck('assignable_id')
            ->unique()
            ->values();

        Log::info('asterisk-drain: notifying affected users', [
            'backend' => $backend,
            'affected_endpoints' => $affectedEndpoints,
            'user_ids' => $userIds->all(),
        ]);

        foreach ($userIds as $userId) {
            AsteriskDrainInitiated::dispatch((int) $userId, $backend);
        }
    }

    /**
     * Hard-disable a backend on both planes. Unlike drain, this
     * also stops Kamailio probing — used when a node is known
     * bad and we don't want our dashboards showing repeated
     * ping failures.
     *
     * @return array{kamailio: bool, haproxy: bool}
     */
    public function disable(string $backend): array
    {
        $kamailioOk = $this->kamailio->setBackendState(
            1,
            "{$backend}:5060",
            'disable',
        );

        [$haproxyOk] = $this->haproxy->disableServer(
            self::HAPROXY_WSS_BACKEND,
            $backend,
        );

        return ['kamailio' => $kamailioOk, 'haproxy' => $haproxyOk];
    }
}
