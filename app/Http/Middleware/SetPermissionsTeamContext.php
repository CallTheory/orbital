<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Syncs Spatie's permissions team context to Jetstream's current_team_id.
 *
 * Must run AFTER StartSession (so auth()->user() is populated) and
 * BEFORE any middleware that checks permissions.
 */
class SetPermissionsTeamContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            app(PermissionRegistrar::class)
                ->setPermissionsTeamId($user->current_team_id);
        }

        return $next($request);
    }
}
