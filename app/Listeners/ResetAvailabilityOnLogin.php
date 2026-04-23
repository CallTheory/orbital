<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\AvailabilityReason;
use App\Models\User;
use App\Services\Telephony\Realtime\QueueMemberSyncer;
use Illuminate\Auth\Events\Login;

/**
 * Reset an operator's availability to `unavailable` on every fresh
 * login, so they always explicitly opt in to taking work rather
 * than being silently thrown into rotation because they happened
 * to leave the tab open on "Available" last time.
 *
 * Gated to platform-role users (operators / supervisors / admins
 * who actually use the operator panel). Client-only users have no
 * availability state to reset — the field is ignored for them —
 * so we skip the work entirely.
 *
 * Also resyncs Asterisk queue members via QueueMemberSyncer so the
 * `paused` flag in the realtime queue_members table lines up with
 * the new state on the same request. Without this the operator
 * could briefly appear un-paused after logging in, catch a call
 * before they'd explicitly set themselves to Available, and be
 * confused about why their phone rang.
 *
 * Auto-discovered by Laravel 12's event discovery — no manual
 * registration in a service provider needed. The `handle` method's
 * typed parameter is what ties it to the Login event.
 */
class ResetAvailabilityOnLogin
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        if (! $user->hasAnyPlatformRole()) {
            return;
        }

        if ($user->availability_status === 'unavailable') {
            // Already where we want them — skip the write and the
            // queue resync. Covers the re-login-in-a-new-tab case
            // where the user was previously signed out cleanly and
            // is coming back to the same state.
            return;
        }

        $user->forceFill([
            'availability_status' => 'unavailable',
            'availability_changed_at' => now(),
        ])->save();

        // Keep Asterisk realtime in lockstep so the operator's
        // softphone stops ringing the moment the login lands,
        // not on their first manual availability toggle.
        app(QueueMemberSyncer::class)->syncForOperator($user);
    }
}
