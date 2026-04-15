<?php

declare(strict_types=1);

namespace App\Http\Controllers\Oidc;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OIDC `userinfo` endpoint at `/oauth/userinfo`. Returns the same
 * claims the `id_token` carries, protected by Passport's `auth:api`
 * middleware so the caller must present a valid access token issued
 * from `/oauth/token`.
 *
 * Downstream tools call this endpoint either instead of or in
 * addition to decoding the id_token — it's a belt-and-suspenders
 * path for OIDC clients that prefer to look up claims fresh rather
 * than trust the JWT they received. pgAdmin hits it on every session
 * start; MinIO Console uses it to refresh the identity claim when
 * the access token is still valid but the id_token has expired.
 *
 * The returned shape matches IdTokenSigner::mint() one-for-one so
 * there's exactly one canonical claim schema across the OIDC flow.
 */
class OidcUserinfoController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null) {
            return response()->json(['error' => 'invalid_token'], 401);
        }

        return response()->json([
            'sub' => (string) $user->id,
            'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null,
            'name' => $user->name,
            'orbital:super_admin' => method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin(),
            'orbital:permissions' => method_exists($user, 'getPermissionNames')
                ? $user->getPermissionNames()->all()
                : [],
        ]);
    }
}
