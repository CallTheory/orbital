<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate routes behind a list of allowed role names. Super-admin always
 * passes regardless of the list.
 *
 * Usage in routes: ->middleware('check.role:operator,supervisor,client_admin')
 */
class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        abort_unless($user, 401);

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        if (! $user->hasAnyRole($roles)) {
            abort(403, 'You do not have the required role for this action.');
        }

        return $next($request);
    }
}
