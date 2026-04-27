<?php

declare(strict_types=1);

namespace App\Models\Passport;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client;
use Laravel\Passport\Scope;

/**
 * Passport Client model subclass that auto-approves the authorization
 * step for in-platform OIDC clients. Registered as the Passport client
 * model via `Passport::useClientModel()` in AppServiceProvider.
 *
 * Why: Passport's default `AuthorizationController` renders a consent
 * view (`Laravel\Passport\Contracts\AuthorizationViewResponse`) when a
 * user first hits `/oauth/authorize` for a given client. Passport 13
 * ships the contract but does NOT bind a default implementation, so
 * hitting the authorize endpoint with any non-trusted client throws
 * `BindingResolutionException`. Our in-platform SSO tools (pgAdmin,
 * MinIO Console) should never show a consent screen anyway — they're
 * first-party infra, the user already logged into Laravel, and the
 * OAuth2 consent step is third-party-integration UX that doesn't
 * apply here.
 *
 * The override flips `skipsAuthorization` to true for clients whose
 * `name` matches the set registered by `SsoSecretsBootstrapper`. Any
 * hypothetical future third-party client (unknown name) still runs
 * through the normal consent flow — where it'll either need a view
 * binding OR an explicit entry in this allowlist.
 */
class FirstPartyClient extends Client
{
    /**
     * Stable client names that auto-approve. Mirrors the OIDC_CLIENTS
     * constant on SsoSecretsBootstrapper — keep them in lockstep. If
     * you add a new first-party OIDC integration, register its
     * client via the bootstrapper AND add the name here.
     *
     * @var list<string>
     */
    private const FIRST_PARTY_NAMES = [
        'pgadmin',
        'minio-console',
    ];

    /**
     * @param  Scope[]  $scopes
     */
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return in_array($this->name, self::FIRST_PARTY_NAMES, true);
    }
}
