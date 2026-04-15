<?php

declare(strict_types=1);

namespace App\Http\Controllers\Oidc;

use Illuminate\Http\JsonResponse;

/**
 * Serves the OIDC discovery document at `/.well-known/openid-configuration`.
 *
 * Downstream tools (pgAdmin, MinIO Console) point at this URL and
 * auto-discover every other endpoint + supported algorithm. Without
 * it they'd need each endpoint configured individually — the whole
 * point of OIDC discovery is to let relying parties bootstrap with
 * a single URL.
 *
 * Most fields are static because Orbital's Passport configuration is
 * fixed: only `authorization_code` grant, only RS256, only `openid` /
 * `email` / `profile` scopes. If we ever support additional grants or
 * algorithms they'll need to be added here too.
 *
 * Cached for an hour on the client side via response headers — the
 * content only changes on app.url rotation.
 */
class OidcDiscoveryController
{
    public function __invoke(): JsonResponse
    {
        $issuer = rtrim(config('app.url'), '/');

        $payload = [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/oauth/authorize',
            'token_endpoint' => $issuer.'/oauth/token',
            'userinfo_endpoint' => $issuer.'/oauth/userinfo',
            'jwks_uri' => $issuer.'/oauth/jwks',
            'revocation_endpoint' => $issuer.'/oauth/tokens',
            'response_types_supported' => ['code'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'scopes_supported' => ['openid', 'email', 'profile'],
            'token_endpoint_auth_methods_supported' => [
                'client_secret_basic',
                'client_secret_post',
            ],
            'claims_supported' => [
                'iss',
                'sub',
                'aud',
                'exp',
                'iat',
                'auth_time',
                'email',
                'email_verified',
                'name',
                'orbital:super_admin',
                'orbital:permissions',
            ],
            'code_challenge_methods_supported' => ['S256'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
        ];

        return response()->json($payload)
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
