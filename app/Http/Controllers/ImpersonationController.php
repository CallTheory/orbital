<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * Super-admin impersonation.
 *
 * Start route stashes the real user id in session and switches auth().
 * While impersonating, the app sees the impersonated user for all
 * permission checks — that's the whole point, so super-admin can see
 * exactly what the tenant user sees.
 */
class ImpersonationController extends Controller
{
    public function start(User $user): RedirectResponse
    {
        $actor = Auth::user();

        abort_unless($actor?->isSuperAdmin(), 403);
        abort_if(Session::has('impersonator_id'), 409, 'Already impersonating.');
        abort_if($user->id === $actor->id, 400, 'Cannot impersonate yourself.');

        Session::put('impersonator_id', $actor->id);
        Auth::login($user);

        // Redirect to `/` and let the root redirect pick the right
        // home panel for the now-impersonated user. For a tenant_user
        // that's /portal — which is exactly the point of impersonation.
        return redirect('/');
    }

    public function stop(): RedirectResponse
    {
        $realId = Session::pull('impersonator_id');
        if ($realId && ($real = User::find($realId))) {
            Auth::login($real);
        }

        return redirect('/admin');
    }
}
