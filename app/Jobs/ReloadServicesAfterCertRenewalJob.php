<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\FailoverAuditLog;
use App\Services\Telephony\AsteriskAmiService;
use App\Services\Telephony\KamailioService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Reloads services after a TLS certificate renewal so they pick
 * up the new PEM files from the shared tls-certs volume.
 *
 * Dispatched by the TlsRenewalWebhookController when acme.sh's
 * deploy hook fires. Each reload is wrapped in a try/catch so
 * one service failure doesn't block the others.
 *
 * Currently reloads:
 *   - Asterisk: AMI `module reload res_pjsip.so` (picks up new
 *     cert on the TLS transport without dropping active calls)
 *   - Kamailio: `tls.reload` JSON-RPC fanned out to every node
 *     so SIP TLS (5061) and operator WSS (7443) start serving
 *     the new chain without restarting (Phase 1b)
 *   - Health cache: `system_health:checks` bust so the dashboard
 *     re-reads the cert file and shows the new expiry immediately
 *
 * nginx, Haraka, and LiveKit reloads will be added as those
 * services are wired to consume the managed cert. For now they
 * either don't have TLS configured (Haraka), terminate TLS via
 * nginx (Reverb), or need a container restart (LiveKit) which
 * the operator does manually after verifying the renewal
 * succeeded on the dashboard.
 */
class ReloadServicesAfterCertRenewalJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        Log::info('TLS cert renewed — reloading services');

        // Asterisk: reload PJSIP to pick up the new cert on TLS transports
        try {
            $ami = app(AsteriskAmiService::class);
            $ami->reload();
            Log::info('Asterisk PJSIP reloaded after cert renewal');
            FailoverAuditLog::record('tls', 'reload', 'asterisk', true, 'PJSIP reload via AMI');
        } catch (\Throwable $e) {
            Log::warning('Failed to reload Asterisk after cert renewal', [
                'error' => $e->getMessage(),
            ]);
            FailoverAuditLog::record('tls', 'reload', 'asterisk', false, $e->getMessage());
        }

        // Kamailio: tls.reload across every node in the dispatcher
        // pool. Without this, only the kamailio-1 node would serve
        // the new cert until kamailio-2 restarts.
        try {
            $result = app(KamailioService::class)->reloadTls();
            Log::info('Kamailio TLS reloaded after cert renewal', [
                'results' => $result['results'],
            ]);
            FailoverAuditLog::record(
                tier: 'tls',
                action: 'reload',
                target: 'kamailio',
                success: $result['ok'],
                output: $result['output'],
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to reload Kamailio after cert renewal', [
                'error' => $e->getMessage(),
            ]);
            FailoverAuditLog::record('tls', 'reload', 'kamailio', false, $e->getMessage());
        }

        // Bust the health cache so the TLS card on the dashboard
        // re-reads the new cert file and shows the updated expiry
        // on the next poll, not the next cache expiration.
        Cache::forget('system_health:checks');

        Log::info('TLS cert renewal reload complete');
    }
}
