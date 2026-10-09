<?php

use App\Http\Controllers\Admin\OrchestrationController;
use App\Http\Controllers\Api\AgentPersonaController;
use App\Http\Controllers\Api\CallLogController;
use App\Http\Controllers\Api\CallSessionController;
use App\Http\Controllers\Api\EdgeDispatcherController;
use App\Http\Controllers\Api\ExtensionController;
use App\Http\Controllers\Api\InboundMailController;
use App\Http\Controllers\Api\InboundMessageController;
use App\Http\Controllers\Api\KnowledgeController;
use App\Http\Controllers\Api\LivekitWebhookController;
use App\Http\Controllers\Api\TlsRenewalWebhookController;
use App\Http\Controllers\Api\VoicemailWebhookController;
use App\Support\Release;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/**
 * Release identity — version, commit, license, and the corresponding
 * source URL for this exact build.
 *
 * Unauthenticated on purpose. It carries no tenant data and it's the
 * machine-readable half of the AGPL section 13 offer served by /source;
 * it also gives the Helm chart and any external monitor a cheap way to
 * confirm which build a pod is running without exec'ing into it.
 */
Route::get('/version', fn () => Release::toArray());

/**
 * Agent worker liveness heartbeat. The Python worker's docker HEALTHCHECK
 * POSTs to this endpoint every 30 seconds. The dashboard's Agent Worker
 * health card reads this cache key to decide whether the worker is alive.
 *
 * Auth is a simple shared-secret bearer token (config('services.agent_worker.token'))
 * because the worker isn't a Sanctum-issued user.
 */
Route::post('/agent-worker/heartbeat', function (Request $request) {
    $expected = config('services.agent_worker.token');
    if (! $expected || $request->bearerToken() !== $expected) {
        abort(401);
    }
    Cache::put('agent_worker:heartbeat', now(), now()->addSeconds(120));

    return ['ok' => true];
});

/**
 * Inbound mail webhook. Haraka's SMTP shim POSTs raw RFC822 + envelope
 * here at end-of-DATA. Controller writes the blob to MinIO, creates a
 * stub EmailMessage row, dispatches ProcessInboundEmailJob on the
 * `inbound-mail` queue, returns 202. All heavy lifting happens on the
 * queue so Haraka gets a fast response and the sender doesn't time out.
 */
Route::post('/mail/inbound', [InboundMailController::class, 'store'])
    ->middleware('inbound-mail-token');

/**
 * RCPT-TO validation for Haraka. Lets the SMTP shim reject
 * mail for unknown account_numbers at the protocol level
 * (550 Unknown recipient) instead of accepting and queueing
 * garbage Laravel would only drop. 60-second cache inside
 * the controller so retry bursts don't hammer the DB.
 */
Route::get('/mail/validate-recipient', [InboundMailController::class, 'validateRecipient'])
    ->middleware('inbound-mail-token');

/**
 * Inbound messaging webhook — SMS/MMS and the other text transports.
 * The carrier POSTs here; the driver named in the path verifies the
 * signature, and everything past that is provider-agnostic.
 *
 * NO auth middleware, deliberately: each provider signs differently
 * (Twilio HMACs the URL plus sorted params, others use bearer tokens or
 * mTLS), so verification belongs to the driver, which is the only thing
 * that knows the scheme. The controller calls verify() before reading a
 * single field out of the payload and 403s on failure.
 *
 * Rate-limited because it's public: signature verification is the real
 * defence, but a limit bounds the damage from a provider malfunctioning
 * or a leaked secret, and keeps a flood off the Horizon queue.
 */
Route::post('/messaging/inbound/{provider}', InboundMessageController::class)
    ->middleware('throttle:messaging-inbound')
    ->name('messaging.inbound');

/**
 * Voicemail webhook. Asterisk's externnotify hook (notify-voicemail.sh
 * inside the asterisk container) POSTs here whenever a new mailbox
 * recording lands. Creates a Voicemail row, queues transcription +
 * email. Auth via the X-Voicemail-Token shared secret.
 */
Route::post('/voicemail/received', [VoicemailWebhookController::class, 'store']);

// LiveKit server-side webhook target. LiveKit POSTs JWT-signed
// JSON bodies for room/egress/track lifecycle events; the
// controller acts on `egress_ended` to register CallRecording rows.
Route::post('/livekit/webhook', LivekitWebhookController::class);

// Agent worker API (Sanctum token auth)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/agent-personas/{agentPersona}', [AgentPersonaController::class, 'show']);
    Route::get('/agent-personas/by-extension/{extension}', [AgentPersonaController::class, 'byExtension']);
    Route::get('/extensions', [ExtensionController::class, 'index']);
    Route::post('/call-logs', [CallLogController::class, 'store']);
    Route::patch('/call-logs/{callLog}/transcript', [CallLogController::class, 'updateTranscript']);
});

/**
 * Knowledge retrieval. Dual-auth: the agent worker uses its shared
 * bearer token (services.agent_worker.token); the operator UI uses its
 * Sanctum session. Client isolation is enforced inside the controller
 * and again in RetrievalService::searchForTeam() before any vector
 * query runs.
 */
Route::post('/knowledge/search', [KnowledgeController::class, 'search']);

/**
 * Call session state — shared between the AI agent worker and the
 * operator softphone. Worker POSTs field captures and step advances
 * with its bearer token; operators read with their Sanctum session.
 * Client isolation is enforced in CallSessionController::show() by
 * comparing current_team_id against the session's stored team_id.
 */
Route::get('/call-sessions/{sessionKey}', [CallSessionController::class, 'show'])
    ->middleware('auth:sanctum');
Route::post('/call-sessions/{sessionKey}/field', [CallSessionController::class, 'captureField']);
Route::post('/call-sessions/{sessionKey}/advance', [CallSessionController::class, 'advance']);
// Call over. Finalises the session and, for clients whose policy keeps
// them, writes whatever was collected as a partial message.
Route::post('/call-sessions/{sessionKey}/end', [CallSessionController::class, 'end']);

/**
 * Orchestration API — backs the Svelte Flow visual editor. Session-
 * cookie auth (same-origin) via the web guard; the editor opens in
 * a popup window on the same host so the existing Laravel session
 * just works. Super-admin only, enforced in the controller.
 */
Route::middleware(['web', 'auth'])->group(function () {
    // Client-scoped: list the client's orchestrations.
    Route::get('/admin/clients/{client}/orchestrations', [OrchestrationController::class, 'index'])
        ->name('api.admin.orchestrations.index');

    // Orchestration-scoped: load / save one orchestration's canvas state.
    Route::get('/admin/orchestrations/{orchestration}', [OrchestrationController::class, 'show'])
        ->name('api.admin.orchestrations.show');
    Route::put('/admin/orchestrations/{orchestration}', [OrchestrationController::class, 'update'])
        ->name('api.admin.orchestrations.update');
});

/**
 * TLS cert renewal webhook. acme.sh's deploy-hook.sh POSTs here
 * after each successful renewal. The controller dispatches a
 * queued job that reloads services consuming the cert. Token-gated
 * by ACME_WEBHOOK_TOKEN so arbitrary callers can't trigger reloads.
 */
Route::post('/tls/renewed', TlsRenewalWebhookController::class);

/**
 * Current Kamailio dispatcher list for the SIP edge VMs, which poll it
 * (same edge token as /tls/renewed) and reload when it changes.
 */
Route::get('/edge/dispatcher', EdgeDispatcherController::class);
