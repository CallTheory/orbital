<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\IdTokenSigner;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Appends an OIDC `id_token` to Passport's `/oauth/token` response
 * whenever the original authorization request included the `openid`
 * scope. This is the single piece of plumbing that turns Passport
 * from a vanilla OAuth2 server into an OIDC provider.
 *
 * Flow:
 *   1. Pass the request through to Passport's token controller.
 *   2. On a 200 response, parse the JSON body.
 *   3. If `openid` is in the returned `scope`, decode the access token
 *      to find the user id, mint an `id_token` via IdTokenSigner, and
 *      inject it into the response JSON.
 *   4. Return the modified response.
 *
 * Why middleware instead of a Passport event listener: the
 * `AccessTokenCreated` event fires but doesn't give us a handle on
 * the outgoing response, so we can't mutate the JSON body from an
 * event listener. Middleware is the clean hook point — it runs
 * AFTER Passport's controller has composed the response and has
 * full access to both the original request and the outbound body.
 *
 * Scoped to `/oauth/token` only — applied in routes/web.php on the
 * Passport token route override.
 */
class AppendOidcIdToken
{
    public function __construct(private readonly IdTokenSigner $signer) {}

    public function handle(Request $request, Closure $next): Response
    {
        // No-op on every route except Passport's token endpoint.
        // The middleware is registered globally on the web stack so
        // it can intercept Passport's auto-registered routes without
        // fighting the service provider's route registration.
        if (! $request->is('oauth/token')) {
            return $next($request);
        }

        $response = $next($request);

        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        $body = $response->getContent();
        if ($body === false || $body === '') {
            return $response;
        }

        $payload = json_decode($body, true);
        if (! is_array($payload) || ! isset($payload['access_token'])) {
            return $response;
        }

        // Passport echoes the granted scope back in the response.
        // If `openid` wasn't one of the requested+granted scopes,
        // this is a plain OAuth2 exchange and no id_token is owed.
        $scope = $payload['scope'] ?? '';
        if (! $this->scopeIncludesOpenid($scope)) {
            return $response;
        }

        $claims = $this->decodeAccessTokenClaims($payload['access_token']);
        if ($claims === null) {
            return $response;
        }

        $userId = $claims['sub'] ?? null;
        $clientId = $claims['aud'] ?? ($claims['client_id'] ?? null);
        if ($userId === null || $clientId === null) {
            return $response;
        }

        // `aud` in a Passport access token can be a string or an
        // array. OIDC `id_token.aud` is a single client id — the
        // one who asked for the token. Peel off the first entry.
        if (is_array($clientId)) {
            $clientId = $clientId[0] ?? null;
        }

        /** @var User|null $user */
        $user = User::find($userId);
        if ($user === null || $clientId === null) {
            return $response;
        }

        $payload['id_token'] = $this->signer->mint($user, (string) $clientId);

        $response->setContent(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response;
    }

    private function scopeIncludesOpenid(mixed $scope): bool
    {
        if (is_string($scope)) {
            return in_array('openid', preg_split('/\s+/', trim($scope)) ?: [], true);
        }
        if (is_array($scope)) {
            return in_array('openid', $scope, true);
        }

        return false;
    }

    /**
     * Decode a Passport-issued JWT access token WITHOUT verifying the
     * signature — we just signed it ourselves two function calls ago,
     * so verification is redundant and would require loading the
     * public key on the hot path. We only care about the claims.
     *
     * @return array<string, mixed>|null
     */
    private function decodeAccessTokenClaims(string $jwt): ?array
    {
        $segments = explode('.', $jwt);
        if (count($segments) !== 3) {
            return null;
        }
        $payload = base64_decode(strtr($segments[1], '-_', '+/'), true);
        if ($payload === false) {
            return null;
        }
        $claims = json_decode($payload, true);

        return is_array($claims) ? $claims : null;
    }
}
