<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Decides whether a user must enrol in two-factor authentication, and
 * how long they have left.
 *
 * The rule is deliberately the same for everyone — platform staff and
 * client portal users alike. A call centre's own operators have access
 * to every client's messages and recordings; the argument for requiring
 * a second factor is stronger for them than for anyone else, not
 * weaker.
 *
 * The only configurable knob is the grace window, and it's per-client
 * because clients differ enormously in how quickly they can be made to
 * do anything. Platform staff use the platform default.
 *
 * Grace is measured from `users.two_factor_grace_started_at`, stamped
 * on the user's first request under the policy — NOT from created_at.
 * Counting from account creation would lock out every existing user the
 * moment enforcement ships, which is how a security improvement becomes
 * an outage.
 */
class TwoFactorPolicy
{
    /**
     * Is two-factor required on this installation at all?
     *
     * A master switch exists because turning this on is a change that
     * can lock people out, and an operator needs to be able to turn it
     * back off from the environment without a code change.
     */
    public function isRequired(): bool
    {
        return (bool) config('orbital.security.two_factor_required', true);
    }

    /**
     * Has this user enrolled a second factor?
     *
     * Confirmed, not merely started: a half-finished TOTP enrolment
     * (secret generated, code never verified) protects nothing.
     */
    public function isEnrolled(User $user): bool
    {
        return $user->two_factor_secret !== null
            && $user->two_factor_confirmed_at !== null;
    }

    /**
     * Days this user's client allows before enforcement bites.
     */
    public function graceDaysFor(User $user): int
    {
        $days = $user->currentTeam?->two_factor_grace_days;

        if ($days === null) {
            $days = config('orbital.security.two_factor_grace_days', 7);
        }

        return max(0, min(30, (int) $days));
    }

    /**
     * Start the clock if it isn't already running.
     *
     * Idempotent, and called from the middleware on every request —
     * which is what gives existing users a full window starting the
     * first time they're seen under the policy rather than a window
     * that expired months before the feature existed.
     */
    public function startGrace(User $user): CarbonImmutable
    {
        if ($user->two_factor_grace_started_at === null) {
            $user->forceFill(['two_factor_grace_started_at' => now()])->save();
        }

        return CarbonImmutable::parse($user->two_factor_grace_started_at);
    }

    public function graceEndsAt(User $user): CarbonImmutable
    {
        return $this->startGrace($user)->addDays($this->graceDaysFor($user));
    }

    /**
     * Whole days left, floored at zero. Used by the banner.
     */
    public function daysRemaining(User $user): int
    {
        if ($this->isEnrolled($user)) {
            return 0;
        }

        return max(0, (int) ceil(now()->floatDiffInDays($this->graceEndsAt($user), false)));
    }

    public function hoursRemaining(User $user): int
    {
        return max(0, (int) ceil(now()->floatDiffInHours($this->graceEndsAt($user), false)));
    }

    /**
     * Is this user out of time?
     */
    public function isBlocked(User $user): bool
    {
        if (! $this->isRequired() || $this->isEnrolled($user)) {
            return false;
        }

        return $this->graceEndsAt($user)->isPast();
    }

    /**
     * Should the countdown banner be shown?
     */
    public function shouldWarn(User $user): bool
    {
        return $this->isRequired() && ! $this->isEnrolled($user);
    }

    /**
     * Last 48 hours — the banner turns red.
     */
    public function isUrgent(User $user): bool
    {
        return $this->shouldWarn($user) && $this->hoursRemaining($user) <= 48;
    }

    /**
     * Where to send a blocked user. Their own panel's security page, so
     * they land somewhere they can actually act rather than on a
     * forbidden error.
     */
    public function securityUrlFor(User $user): string
    {
        $panel = match (true) {
            method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin() => 'admin',
            method_exists($user, 'hasAnyPlatformRole') && $user->hasAnyPlatformRole() => 'operator',
            default => 'portal',
        };

        return '/'.$panel.'/security';
    }
}
