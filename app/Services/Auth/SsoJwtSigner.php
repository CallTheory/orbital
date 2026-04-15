<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;

/**
 * Mints HS256 JWTs for the Redis Commander SSO redirect flow.
 *
 * Redis Commander's `/sso?access_token=<jwt>` endpoint validates
 * the token against the shared secret configured by SSO_JWT_SECRET
 * and trusts `iss` matches SSO_ISSUER. Every claim below is read by
 * `jwtVerifySso` in the image's `lib/app.js` — `sub` + `email` +
 * `name` are logged, `exp` is used to expire the single-use cache
 * entry, and `iss` / `alg` are validated against the Commander config.
 *
 * Hand-rolled HS256 — Orbital doesn't ship a JWT library today and
 * this is 30 lines for a trivial claim set. If we ever grow a third
 * JWT use case I'd pull in `firebase/php-jwt`; at two call sites it's
 * a wash.
 */
class SsoJwtSigner
{
    /** Default lifetime for a Redis Commander SSO token. */
    private const REDIS_COMMANDER_TTL = 60;

    public function signForRedisCommander(User $user, ?int $ttl = null): string
    {
        $secret = (string) config('services.redis_commander.sso_secret');
        $issuer = (string) config('services.redis_commander.sso_issuer');

        if ($secret === '') {
            throw new \RuntimeException(
                'Redis Commander SSO secret is missing. Run the SSO Secrets bootstrapper under /admin/setup.'
            );
        }

        $now = time();
        $exp = $now + ($ttl ?? self::REDIS_COMMANDER_TTL);

        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $payload = [
            'iss' => $issuer,
            'sub' => (string) $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'iat' => $now,
            'exp' => $exp,
        ];

        $encodedHeader = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signingInput = $encodedHeader.'.'.$encodedPayload;

        $signature = hash_hmac('sha256', $signingInput, $secret, true);

        return $signingInput.'.'.$this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
