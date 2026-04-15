<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Laravel\Passport\Passport;

/**
 * Mints OIDC `id_token` JWTs for the OAuth2 token response.
 *
 * Laravel Passport speaks OAuth2 — this class is the thin shim that
 * turns Passport into an OIDC provider by issuing an RS256-signed
 * `id_token` alongside the normal `access_token`. Signed with the
 * same RSA private key Passport uses for its own tokens so downstream
 * tools can fetch the public half via /oauth/jwks and verify without
 * a shared secret.
 *
 * Claims follow the OIDC core spec:
 *   iss        — app URL (issuer)
 *   sub        — user id (stable subject identifier)
 *   aud        — client id (the OAuth2 client requesting the token)
 *   exp        — expiry (issue time + TTL, default 15 min)
 *   iat        — issued at
 *   auth_time  — when the user authenticated (we use iat since
 *                Passport doesn't surface the actual session start)
 *   email      — user's email
 *   email_verified — always true because Orbital uses email/password
 *                    auth and email_verified_at is required
 *   name       — user's display name
 *
 * Orbital-specific claims (prefixed with `orbital:`):
 *   orbital:super_admin — boolean; lets downstream tools grant
 *                          admin privileges without round-tripping
 *                          back to Laravel for a permission check
 *   orbital:permissions — flat array of Spatie permission names the
 *                          user holds. Downstream tools can map these
 *                          to their own role system (e.g. MinIO's
 *                          policy-by-claim) without API calls.
 */
class IdTokenSigner
{
    /** Token lifetime in seconds. Short — downstream tools exchange this for their own session immediately. */
    private const TTL = 900;

    public function mint(User $user, string $clientId, ?int $issuedAt = null): string
    {
        $issuedAt ??= time();
        $expiresAt = $issuedAt + self::TTL;

        $header = [
            'typ' => 'JWT',
            'alg' => 'RS256',
            'kid' => $this->keyId(),
        ];

        $payload = [
            'iss' => rtrim(config('app.url'), '/'),
            'sub' => (string) $user->id,
            'aud' => $clientId,
            'iat' => $issuedAt,
            'auth_time' => $issuedAt,
            'exp' => $expiresAt,
            'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null,
            'name' => $user->name,
            'orbital:super_admin' => method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin(),
            'orbital:permissions' => method_exists($user, 'getPermissionNames')
                ? $user->getPermissionNames()->all()
                : [],
        ];

        $encodedHeader = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signingInput = $encodedHeader.'.'.$encodedPayload;

        $privateKey = $this->loadPrivateKey();
        openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return $signingInput.'.'.$this->base64UrlEncode($signature);
    }

    /**
     * Stable key id derived from a hash of the public key. Downstream
     * tools use this `kid` claim to pick the right entry from the
     * JWKS endpoint when multiple keys are published. We only publish
     * one, but tools still expect the claim to match.
     */
    public function keyId(): string
    {
        $publicKey = file_get_contents(Passport::keyPath('oauth-public.key'));
        return substr(hash('sha256', $publicKey), 0, 16);
    }

    /** @return \OpenSSLAsymmetricKey */
    private function loadPrivateKey()
    {
        $pem = file_get_contents(Passport::keyPath('oauth-private.key'));
        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new \RuntimeException('Failed to load Passport private key for OIDC signing.');
        }
        return $key;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
