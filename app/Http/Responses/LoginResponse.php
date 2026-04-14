<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * Orbital's post-login redirect policy.
 *
 * Login is the only auth entry point in the platform, so this response
 * object is where the "where does this user go now?" logic lives.
 *
 * Precedence:
 *   1. If Laravel captured an `intended` URL before the login bounce,
 *      honor it. The destination's own middleware still runs — so an
 *      operator who deep-linked to `/admin` gets sent there, and then
 *      PanelRedirect bounces them to `/operator`. One extra hop, not
 *      a loop.
 *   2. Otherwise, role-based default:
 *      - super_admin            → /admin
 *      - any other platform role → /operator
 *      - everyone else           → /portal
 */
class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        $default = $this->defaultFor($user);

        return redirect()->intended($default);
    }

    protected function defaultFor(?User $user): string
    {
        if (! $user) {
            return '/login';
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return '/admin';
        }

        if (method_exists($user, 'hasAnyPlatformRole') && $user->hasAnyPlatformRole()) {
            return '/operator';
        }

        return '/portal';
    }
}
