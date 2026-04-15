<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level gate for admin-side tooling endpoints. Matches the
 * permission model used by `AdminPanelProvider::userCanAccessTool`:
 *   - super_admin always passes
 *   - otherwise the user must hold the named `tooling.*` permission
 *
 * Declared as the `tool` middleware alias in bootstrap/app.php so
 * routes can gate themselves with `middleware('tool:tooling.grafana')`.
 * Returning 403 instead of redirecting because these routes are only
 * ever hit by signed-in staff navigating from the admin panel — a
 * redirect would be confusing.
 */
class CheckToolPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $next($request);
        }

        if (method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo($permission)) {
            return $next($request);
        }

        abort(403, "Missing required permission: {$permission}");
    }
}
