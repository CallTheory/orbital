<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allows the request through only if the authenticated user holds any
 * team-less platform role (super_admin, or any operator-defined platform
 * role like operator/supervisor/whatever-they-renamed-it-to).
 *
 * Used by the /operator workspace routes so they don't have to know
 * what platform roles exist by name.
 */
class RequirePlatformRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user, 401);

        if ($user->isSuperAdmin() || $user->hasAnyPlatformRole()) {
            return $next($request);
        }

        abort(403, 'Platform role required.');
    }
}
