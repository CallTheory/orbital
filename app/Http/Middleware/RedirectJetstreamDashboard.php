<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Catches the Jetstream dashboard entry points (/user/profile,
 * /teams/*, /user/api-tokens) and redirects them to the user's
 * home Filament panel profile, which is styled consistently with
 * the rest of the admin/operator/portal surfaces.
 *
 * We can't easily delete Jetstream's routes — they're registered
 * by the package's service provider — so we intercept them at the
 * middleware layer instead. Registered in config/jetstream.php's
 * middleware stack so only Jetstream-owned routes hit it.
 */
class RedirectJetstreamDashboard
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->path();

        $matches = str_starts_with($path, 'user/profile')
            || str_starts_with($path, 'teams')
            || str_starts_with($path, 'user/api-tokens');

        if (! $matches) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }

        $panel = match (true) {
            method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin() => 'admin',
            method_exists($user, 'hasAnyPlatformRole') && $user->hasAnyPlatformRole() => 'operator',
            default => 'portal',
        };

        return redirect("/{$panel}/profile");
    }
}
