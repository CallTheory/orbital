<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\Mail\AttachmentDownloadController;
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

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');
});

// Impersonation — super-admin only
Route::middleware(['auth', config('jetstream.auth_session')])->group(function () {
    Route::post('/impersonate/{user}', [ImpersonationController::class, 'start'])
        ->name('impersonate.start');
    Route::post('/impersonate/stop', [ImpersonationController::class, 'stop'])
        ->name('impersonate.stop');
});

// Email attachment download + raw MIME viewer. Authed via the
// default web guard; tenant-access checks live in the controller.
// Signed URLs aren't used here because the audience is logged-in
// platform staff; if we later expose attachments to tenants, we
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

// Public chat surface — anonymous text conversation with a tenant's
// agent persona. The session is anchored by a random public_token
// stashed in the browser session so refreshes preserve the transcript.
// No auth: the URL itself is the shared secret.
Route::get('/chat/{tenant}/{persona}', [ChatController::class, 'show'])
    ->name('chat.show');
