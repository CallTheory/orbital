<?php

use App\Http\Controllers\Api\AgentPersonaController;
use App\Http\Controllers\Api\CallLogController;
use App\Http\Controllers\Api\CallSessionController;
use App\Http\Controllers\Api\ExtensionController;
use App\Http\Controllers\Api\InboundMailController;
use App\Http\Controllers\Api\KnowledgeController;
use App\Http\Controllers\Api\TlsRenewalWebhookController;
use App\Http\Controllers\Api\VoicemailWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

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
 * Voicemail webhook. Asterisk's externnotify hook (notify-voicemail.sh
 * inside the asterisk container) POSTs here whenever a new mailbox
 * recording lands. Creates a Voicemail row, queues transcription +
 * email. Auth via the X-Voicemail-Token shared secret.
 */
Route::post('/voicemail/received', [VoicemailWebhookController::class, 'store']);

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
 * Sanctum session. Tenant isolation is enforced inside the controller
 * and again in RetrievalService::searchForTeam() before any vector
 * query runs.
 */
Route::post('/knowledge/search', [KnowledgeController::class, 'search']);

/**
 * Call session state — shared between the AI agent worker and the
 * operator softphone. Worker POSTs field captures and step advances
 * with its bearer token; operators read with their Sanctum session.
 * Tenant isolation is enforced in CallSessionController::show() by
 * comparing current_team_id against the session's stored team_id.
 */
Route::get('/call-sessions/{sessionKey}', [CallSessionController::class, 'show'])
    ->middleware('auth:sanctum');
Route::post('/call-sessions/{sessionKey}/field', [CallSessionController::class, 'captureField']);
Route::post('/call-sessions/{sessionKey}/advance', [CallSessionController::class, 'advance']);

/**
 * TLS cert renewal webhook. acme.sh's deploy-hook.sh POSTs here
 * after each successful renewal. The controller dispatches a
 * queued job that reloads services consuming the cert. Token-gated
 * by ACME_WEBHOOK_TOKEN so arbitrary callers can't trigger reloads.
 */
Route::post('/tls/renewed', TlsRenewalWebhookController::class);
