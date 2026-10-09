<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Events\AsteriskDrainInitiated;
use App\Models\AsteriskBackend;
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
        $this->recordState($backend, 'drain');

        $haproxyOk = $this->haproxyAction('disableServer', $backend);

        // Find users whose softphone is registered on the draining
        // Asterisk and broadcast a Filament toast directly to each
        // one via their private notifications channel. This avoids
        // the public-channel + client-side filter path that gave
        // us duplicate-fire + panel-scoping headaches — Filament's
        // ->broadcast() targets a user's authenticated panel
        // sessions exclusively, and the notification stack
        // auto-subscribes whenever the user is logged in.
        $affectedEndpoints = DB::table('ps_contacts')
            ->whereIn('reg_server', $this->nodeNames($backend))
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
        $this->recordState($backend, 'active');

        $haproxyOk = $this->haproxyAction('enableServer', $backend);

        return ['kamailio' => $kamailioOk, 'haproxy' => $haproxyOk];
    }

    /**
     * The SIP edge setup (Kubernetes) has no HAProxy: softphones reach
     * Asterisk through Kamailio, so the dispatcher state covers them.
     * There the HAProxy step is skipped and counts as done.
     */
    protected function edgeMode(): bool
    {
        return (string) config('telephony.kamailio.asterisk_discovery_host', '') !== '';
    }

    protected function haproxyAction(string $method, string $backend): bool
    {
        if ($this->edgeMode()) {
            return true;
        }

        [$ok] = $this->haproxy->{$method}(self::HAPROXY_WSS_BACKEND, $backend);

        return $ok;
    }

    /**
     * Remember the operator's choice so the edges' dispatcher list
     * (/api/edge/dispatcher) keeps it across reloads.
     */
    protected function recordState(string $backend, string $state): void
    {
        AsteriskBackend::query()
            ->where('hostname', $backend)
            ->update(['dispatch_state' => $state]);
    }

    /**
     * reg_server values that mean "registered on this backend": the
     * address it's drained by, plus its systemname when that differs
     * (discovered pods are keyed by IP but stamp the pod name).
     *
     * @return list<string>
     */
    protected function nodeNames(string $backend): array
    {
        $nodeName = AsteriskBackend::query()->where('hostname', $backend)->value('node_name');

        return array_values(array_unique(array_filter([$backend, $nodeName])));
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
        $this->recordState($backend, 'disable');

        $haproxyOk = $this->haproxyAction('disableServer', $backend);

        return ['kamailio' => $kamailioOk, 'haproxy' => $haproxyOk];
    }
}
