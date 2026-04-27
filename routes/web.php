<?php

use App\Http\Controllers\Admin\Sso\GrafanaProxyController;
use App\Http\Controllers\Admin\Sso\IcecastProxyController;
use App\Http\Controllers\Admin\Sso\MailpitProxyController;
use App\Http\Controllers\Admin\Sso\PgAdminProxyController;
use App\Http\Controllers\Admin\Sso\PrometheusProxyController;
use App\Http\Controllers\Admin\Sso\RedisCommanderProxyController;
use App\Http\Controllers\Admin\Sso\RedisCommanderSsoController;
use App\Http\Controllers\Admin\Sso\SeaweedFsProxyController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\Mail\AttachmentDownloadController;
use App\Http\Controllers\Oidc\OidcDiscoveryController;
use App\Http\Controllers\Oidc\OidcJwksController;
use App\Http\Controllers\Oidc\OidcUserinfoController;
use Illuminate\Support\Facades\Route;

/**
 * Root URL is a pure redirect. Authenticated users go to their role's
 * home surface; everyone else lands on the unified login page. No
 * welcome / splash page — this is a platform install, not a public site.
 */
Route::get('/', function () {
    $user = auth()->user();
    if (! $user) {
        return redirect()->route('login');
    }

    if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
        return redirect('/admin');
    }

    if (method_exists($user, 'hasAnyPlatformRole') && $user->hasAnyPlatformRole()) {
        return redirect('/operator');
    }

    return redirect('/portal');
})->name('home');

// Impersonation — super-admin only
Route::middleware(['auth', config('jetstream.auth_session')])->group(function () {
    Route::post('/impersonate/{user}', [ImpersonationController::class, 'start'])
        ->name('impersonate.start');
    Route::post('/impersonate/stop', [ImpersonationController::class, 'stop'])
        ->name('impersonate.stop');
});

// Email attachment download + raw MIME viewer. Authed via the
// default web guard; client-access checks live in the controller.
// Signed URLs aren't used here because the audience is logged-in
// platform staff; if we later expose attachments to clients, we
// can switch this to a signed route without breaking the shape.
Route::middleware(['auth', config('jetstream.auth_session')])->group(function () {
    Route::get('/mail/attachments/{attachment}/download', [AttachmentDownloadController::class, 'download'])
        ->name('mail.attachments.download');
    Route::get('/mail/messages/{emailMessage}/raw', [AttachmentDownloadController::class, 'rawMessage'])
        ->name('mail.messages.raw');
});

// /operator and /portal are both Filament panels now — see
// OperatorPanelProvider and PortalPanelProvider. Filament registers
// their routes on boot; no web.php entries needed.

// Public chat surface — anonymous text conversation with a client's
// agent persona. The session is anchored by a random public_token
// stashed in the browser session so refreshes preserve the transcript.
// No auth: the URL itself is the shared secret.
Route::get('/chat/{client}/{persona}', [ChatController::class, 'show'])
    ->name('chat.show');

// Intake-flow visual editor — opens as its own popup from the
// Filament Intake Flows list. Session-cookie auth via the web
// guard; super-admin enforcement happens inline.
Route::middleware(['auth'])->group(function () {
    Route::get('/admin/flow-editor/{orchestration}', function (\App\Models\Orchestration $orchestration) {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        return view('admin.flow-editor', [
            'orchestration' => $orchestration,
            'client' => $orchestration->team,
            'focusFlowId' => request()->integer('focus') ?: null,
        ]);
    })->name('admin.flow-editor');
});

// ──────────────────────────────────────────────────────────────────
// Unified SSO — admin-side control panel entry points.
//
// Session-shared (Horizon / Pulse / Telescope) needs no route —
// Laravel's session cookie already carries auth.
//
// Redis Commander uses JWT-via-redirect: Laravel signs a short-lived
// token and 302s into Commander's /sso endpoint.
//
// Grafana uses a full reverse-proxy with X-WEBAUTH-USER injection —
// every request is forwarded by Laravel so the session check runs
// on each hit.
//
// pgAdmin + MinIO Console use the OIDC endpoints below
// (/.well-known/openid-configuration and friends).
// ──────────────────────────────────────────────────────────────────

Route::middleware(['auth'])->prefix('admin')->group(function () {
    // Legacy JWT SSO route — kept for backward compat but the
    // nav now points at the proxy route below instead.
    Route::get('sso/redis-commander', RedisCommanderSsoController::class)
        ->middleware('tool:tooling.redis_commander')
        ->name('admin.sso.redis-commander');

    Route::any('redis-commander/{path?}', [RedisCommanderProxyController::class, 'forward'])
        ->where('path', '.*')
        ->middleware('tool:tooling.redis_commander')
        ->name('admin.redis-commander.forward');

    Route::any('grafana/{path?}', [GrafanaProxyController::class, 'forward'])
        ->where('path', '.*')
        ->middleware('tool:tooling.grafana')
        ->name('admin.grafana.forward');

    Route::any('icecast/{path?}', [IcecastProxyController::class, 'forward'])
        ->where('path', '.*')
        ->middleware('tool:tooling.icecast')
        ->name('admin.icecast.forward');

    Route::any('seaweedfs/filer/{path?}', [SeaweedFsProxyController::class, 'filer'])
        ->where('path', '.*')
        ->middleware('tool:tooling.seaweedfs')
        ->name('admin.seaweedfs.filer');

    Route::any('seaweedfs/master/{path?}', [SeaweedFsProxyController::class, 'master'])
        ->where('path', '.*')
        ->middleware('tool:tooling.seaweedfs')
        ->name('admin.seaweedfs.master');

    Route::any('pgadmin/{path?}', [PgAdminProxyController::class, 'forward'])
        ->where('path', '.*')
        ->middleware('tool:tooling.pgadmin')
        ->name('admin.pgadmin.forward');

    Route::any('prometheus/{path?}', [PrometheusProxyController::class, 'forward'])
        ->where('path', '.*')
        ->middleware('tool:tooling.prometheus')
        ->name('admin.prometheus.forward');

    Route::any('haproxy-stats/{path?}', [\App\Http\Controllers\Admin\Sso\HAProxyStatsProxyController::class, 'forward'])
        ->where('path', '.*')
        ->middleware('tool:tooling.haproxy_stats')
        ->name('admin.haproxy-stats.forward');

    Route::any('mailpit/{path?}', [MailpitProxyController::class, 'forward'])
        ->where('path', '.*')
        ->middleware('tool:tooling.mailpit')
        ->name('admin.mailpit.forward');
});

// ──────────────────────────────────────────────────────────────────
// OIDC provider endpoints — discovery + JWKS + userinfo. Passport
// registers /oauth/authorize + /oauth/token + /oauth/tokens from
// its service provider; we add the OIDC-specific bits on top.
// ──────────────────────────────────────────────────────────────────
Route::get('.well-known/openid-configuration', OidcDiscoveryController::class)
    ->name('oidc.discovery');
Route::get('oauth/jwks', OidcJwksController::class)
    ->name('oidc.jwks');
Route::get('oauth/userinfo', OidcUserinfoController::class)
    ->middleware('auth:api')
    ->name('oidc.userinfo');

// ──────────────────────────────────────────────────────────────────
// Client invitation acceptance — public (no auth middleware). One
// page handles four auth states: brand-new user, existing user,
// already signed in as the invited email, signed in as the wrong
// email. The controller resolves which; the view branches.
// ──────────────────────────────────────────────────────────────────
Route::get('/invite/{token}', [\App\Http\Controllers\ClientInvitationController::class, 'show'])
    ->name('invitation.show');
Route::post('/invite/{token}', [\App\Http\Controllers\ClientInvitationController::class, 'accept'])
    ->name('invitation.accept');

