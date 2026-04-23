<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bounces an authenticated user to their own home panel when they land
 * on a panel they don't have access to.
 *
 * Registered inside every panel's authMiddleware chain so it runs AFTER
 * Filament's Authenticate (we know who the user is) but BEFORE the
 * page components boot. When a user hits the wrong panel, Filament would
 * normally call canAccessPanel() and abort(403). We intercept first and
 * turn that into a redirect to the user's actual home surface.
 *
 * Resolution chain (same as LoginResponse::defaultFor):
 *   super_admin              → /admin
 *   any other platform role  → /operator
 *   client_user / everyone   → /portal
 */
class PanelRedirect
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $currentPanel = Filament::getCurrentPanel();
        if (! $currentPanel) {
            return $next($request);
        }

        // User has standing on this panel → let the request through.
        if ($user->canAccessPanel($currentPanel)) {
            return $next($request);
        }

        // Otherwise, send them to their actual home panel.
        return redirect($this->homeFor($user));
    }

    protected function homeFor($user): string
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return '/admin';
        }

        if (method_exists($user, 'hasAnyPlatformRole') && $user->hasAnyPlatformRole()) {
            return '/operator';
        }

        return '/portal';
    }
}
