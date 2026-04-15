<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the authenticated user's locale preference on every
 * request. Timezone is NOT applied here — it's intentionally
 * kept out of process state so database writes continue to use
 * the UTC config('app.timezone'). Display-side code should read
 * `Auth::user()->displayTimezone()` and pass it to Filament's
 * `->timezone()` column modifier (or Carbon's `->setTimezone()`)
 * at render time.
 *
 * The system-level `config('app.locale')` is the fallback for
 * logs, jobs, and unauthenticated requests. Once a user signs
 * in, their profile preference overrides the request-scoped
 * locale so the UI translator resolves strings in their
 * preferred language.
 */
class ApplyUserPreferences
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user && ! empty($user->locale)) {
            app()->setLocale($user->locale);
        }

        return $next($request);
    }
}
