<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Auth\TwoFactorPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirects users who are out of their two-factor grace window to their
 * panel's security page.
 *
 * Runs on all three panels. What it must NOT do is trap anyone:
 *
 *   - The security page itself is always reachable, or a blocked user
 *     has nowhere to go and no way to comply.
 *   - Logout is always reachable, so a blocked user can at least get
 *     out.
 *   - Livewire's own endpoints pass through. The security page is a
 *     Livewire component; redirecting its XHR calls would break the
 *     enrolment flow — the exact thing we're pushing people towards.
 *   - Non-GET requests pass through. Redirecting a POST loses the
 *     payload and produces a confusing failure; the next GET catches
 *     them anyway.
 *
 * The grace clock starts here, on first sight of the user, rather than
 * from their created_at — see TwoFactorPolicy.
 */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $policy = app(TwoFactorPolicy::class);

        if (! $policy->isRequired() || $policy->isEnrolled($user)) {
            return $next($request);
        }

        // Starts the clock the first time we see them under the policy.
        $policy->startGrace($user);

        if (! $policy->isBlocked($user)) {
            return $next($request);
        }

        if ($this->isExempt($request, $policy->securityUrlFor($user))) {
            return $next($request);
        }

        return redirect($policy->securityUrlFor($user))
            ->with('two_factor_required', true);
    }

    /**
     * Requests that must never be redirected.
     */
    private function isExempt(Request $request, string $securityUrl): bool
    {
        if (! $request->isMethod('GET')) {
            return true;
        }

        if ($request->ajax() || $request->wantsJson()) {
            return true;
        }

        $path = '/'.ltrim($request->path(), '/');

        // The destination itself, plus every panel's security page —
        // a user whose panel changes mid-session must still be able to
        // reach the one they're on.
        if ($path === $securityUrl || str_ends_with($path, '/security')) {
            return true;
        }

        return $request->is('livewire/*', 'logout', 'login', 'source', 'up', 'metrics');
    }
}
