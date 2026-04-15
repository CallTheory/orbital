<?php

namespace App\Providers;

use App\Models\CallQueue;
use App\Models\Extension;
use App\Models\RoutingRule;
use App\Models\SipTrunk;
use App\Models\User;
use App\Observers\QuotaObserver;
use App\Observers\StaffExtensionObserver;
use App\Observers\TelephonyObserver;
use App\Services\Tenancy\TenantPermissionGatekeeper;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Laravel\Jetstream\Events\TeamSwitched;
use Laravel\Passport\Passport;
use Spatie\Permission\PermissionRegistrar;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(\App\Services\Bootstrap\BootstrapRegistry::class);
    }

    public function boot(): void
    {
        $this->registerBootstrappers();

        // Telephony config regeneration on model changes
        SipTrunk::observe(TelephonyObserver::class);
        Extension::observe(TelephonyObserver::class);
        RoutingRule::observe(TelephonyObserver::class);
        CallQueue::observe(TelephonyObserver::class);

        // Quota enforcement on user-created records
        SipTrunk::observe(QuotaObserver::class);
        Extension::observe(QuotaObserver::class);

        // Staff softphone extension lifecycle (auto-cleanup on user deletion)
        User::observe(StaffExtensionObserver::class);

        // When a user switches teams, clear Spatie's permission cache so the
        // new team context is evaluated fresh.
        $this->app['events']->listen(TeamSwitched::class, function () {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });

        // Every fresh login resets operators to `unavailable` so they
        // always opt in to taking work after signing in rather than
        // being silently thrown into rotation because their previous
        // tab happened to leave them on Available. Listener gates on
        // `hasAnyPlatformRole` so tenant-only users (who don't have
        // an availability state at all) are a cheap no-op.
        Event::listen(Login::class, \App\Listeners\ResetAvailabilityOnLogin::class);

        // OIDC clients for the in-platform control panels (pgAdmin,
        // MinIO Console, future tools) are first-party — they're
        // infra we own, not third-party apps that need explicit
        // user consent. `FirstPartyClient` overrides
        // `skipsAuthorization` to return true for our registered
        // client names, so the consent step is skipped entirely.
        Passport::useClientModel(\App\Models\Passport\FirstPartyClient::class);

        // OIDC scope registration — without this, Passport's
        // ScopeRepository rejects every authorize request asking
        // for `openid email profile` with
        // `invalid_scope: The requested scope is invalid, unknown,
        // or malformed`. League OAuth2 looks up each requested
        // scope in the `tokensCan` map and fails the whole request
        // if any are missing. Descriptions are only user-facing
        // during the consent step, which our first-party clients
        // skip — so the strings are informational only.
        Passport::tokensCan([
            'openid' => 'Authenticate via OpenID Connect',
            'email' => 'Access your email address',
            'profile' => 'Access your profile information',
        ]);

        // Passport 13 type-hints `AuthorizationViewResponse` as a
        // method parameter on its AuthorizationController, so Laravel
        // resolves it from the container BEFORE the method body runs.
        // If the contract isn't bound, dependency resolution throws
        // and the controller never executes — which means the
        // `skipsAuthorization()` short-circuit above never gets a
        // chance to run either.
        //
        // Binding the contract to a stub blade view satisfies the
        // container. For first-party clients the controller returns
        // an approve-and-redirect response WITHOUT ever rendering
        // this view — the binding is load-bearing purely to keep
        // dependency injection happy. If we ever add a real
        // third-party OAuth client later, we'll publish Passport's
        // own consent template and point this binding at it.
        Passport::authorizationView('auth.passport-consent-stub');

        // Boot-time invariant check: no platform-only permission should ever
        // be on a tenant's allow list. Logs critical if violated.
        $this->assertNoPlatformPermissionsInTenantGrants();

        // Pulse access gate. Pulse doesn't ship a service provider hook,
        // so we register the `viewPulse` gate here. Anyone with the
        // `tooling.pulse` permission (or super_admin) can access /pulse.
        Gate::define('viewPulse', function (?User $user) {
            if (! $user) {
                return false;
            }
            if ($user->isSuperAdmin()) {
                return true;
            }
            return $user->hasPermissionTo('tooling.pulse');
        });
    }

    /**
     * Register every Bootstrapper implementation with the shared
     * BootstrapRegistry. Registration order determines execution
     * order when `orbital:bootstrap` runs them all.
     */
    protected function registerBootstrappers(): void
    {
        $registry = $this->app->make(\App\Services\Bootstrap\BootstrapRegistry::class);

        $registry
            ->register($this->app->make(\App\Services\Bootstrap\Bootstrappers\PgvectorBootstrapper::class))
            ->register($this->app->make(\App\Services\Bootstrap\Bootstrappers\MinioBootstrapper::class))
            ->register($this->app->make(\App\Services\Bootstrap\Bootstrappers\AsteriskBootstrapper::class))
            ->register($this->app->make(\App\Services\Bootstrap\Bootstrappers\LiveKitBootstrapper::class))
            ->register($this->app->make(\App\Services\Bootstrap\Bootstrappers\IcecastBootstrapper::class))
            ->register($this->app->make(\App\Services\Bootstrap\Bootstrappers\OllamaBootstrapper::class))
            // SSO secrets — generates shared secrets for the
            // Redis Commander JWT + Grafana reverse-proxy trust
            // chain, and creates OAuth2 clients for pgAdmin and
            // MinIO Console OIDC. Registered after the services
            // whose containers consume the resulting .env values
            // so the admin's first install flow is: pgvector →
            // MinIO → Asterisk → ... → SSO secrets → restart.
            ->register($this->app->make(\App\Services\Bootstrap\Bootstrappers\SsoSecretsBootstrapper::class));
    }

    /**
     * Defensive check: the tenant_permission_grants table must never contain
     * any of the PLATFORM_ONLY permissions. If it does, something has bypassed
     * the gatekeeper — log loudly.
     */
    protected function assertNoPlatformPermissionsInTenantGrants(): void
    {
        // Skip during migrations when the tables may not exist yet.
        if (! Schema::hasTable('tenant_permission_grants') || ! Schema::hasTable('permissions')) {
            return;
        }

        try {
            $count = DB::table('tenant_permission_grants as g')
                ->join('permissions as p', 'p.id', '=', 'g.permission_id')
                ->whereIn('p.name', TenantPermissionGatekeeper::PLATFORM_ONLY)
                ->count();

            if ($count > 0) {
                Log::critical('Platform-only permissions found in tenant_permission_grants', [
                    'count' => $count,
                ]);
            }
        } catch (\Throwable $e) {
            // Tables not ready / connection issue — don't crash boot.
        }
    }
}
