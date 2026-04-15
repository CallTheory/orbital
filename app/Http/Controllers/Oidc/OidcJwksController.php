<?php

declare(strict_types=1);

namespace App\Http\Controllers\Oidc;

use App\Services\Auth\IdTokenSigner;
use Illuminate\Http\JsonResponse;
use Laravel\Passport\Passport;

/**
 * Publishes Passport's public signing key in JWKS (JSON Web Key Set)
 * format at `/oauth/jwks`. Downstream OIDC clients fetch this to
 * verify the RS256 signature on `id_token` JWTs we issue from
 * /oauth/token.
 *
 * The JWKS payload format:
 *   { "keys": [ { "kty": "RSA", "alg": "RS256", "use": "sig",
 *                 "kid": "...", "n": "...", "e": "..." } ] }
 *
 * `n` and `e` are base64url-encoded big integers from the RSA public
 * key — PHP's openssl_pkey_get_details() hands them back in raw binary
 * form and we re-encode.
 *
 * Cached for an hour client-side; the key only rotates if the operator
 * regenerates Passport's keys via `artisan passport:keys --force`.
 */
class OidcJwksController
{
    public function __construct(private readonly IdTokenSigner $signer) {}

    public function __invoke(): JsonResponse
    {
        $pem = file_get_contents(Passport::keyPath('oauth-public.key'));
        $publicKey = openssl_pkey_get_public($pem);
        if ($publicKey === false) {
            throw new \RuntimeException('Failed to parse Passport public key for JWKS publication.');
        }

        $details = openssl_pkey_get_details($publicKey);
        if ($details === false || ! isset($details['rsa'])) {
            throw new \RuntimeException('Public key is not an RSA key — cannot build JWKS.');
        }

        $payload = [
            'keys' => [[
                'kty' => 'RSA',
                'alg' => 'RS256',
                'use' => 'sig',
                'kid' => $this->signer->keyId(),
                'n' => $this->base64UrlEncode($details['rsa']['n']),
                'e' => $this->base64UrlEncode($details['rsa']['e']),
            ]],
        ];

        return response()->json($payload)
            ->header('Cache-Control', 'public, max-age=3600');
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
