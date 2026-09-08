<?php

namespace App\Providers;

use App\Listeners\ResetAvailabilityOnLogin;
use App\Models\CallQueue;
use App\Models\Extension;
use App\Models\Passport\FirstPartyClient;
use App\Models\RoutingRule;
use App\Models\SipTrunk;
use App\Models\User;
use App\Observers\QuotaObserver;
use App\Observers\StaffExtensionObserver;
use App\Observers\TelephonyObserver;
use App\Services\Bootstrap\Bootstrappers\AsteriskBootstrapper;
use App\Services\Bootstrap\Bootstrappers\IcecastBootstrapper;
use App\Services\Bootstrap\Bootstrappers\LiveKitBootstrapper;
use App\Services\Bootstrap\Bootstrappers\OllamaBootstrapper;
use App\Services\Bootstrap\Bootstrappers\PgvectorBootstrapper;
use App\Services\Bootstrap\Bootstrappers\S3BucketBootstrapper;
use App\Services\Bootstrap\Bootstrappers\SsoSecretsBootstrapper;
use App\Services\Bootstrap\BootstrapRegistry;
use App\Services\Clients\ClientPermissionGatekeeper;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Laravel\Jetstream\Events\TeamSwitched;
use Laravel\Passport\Passport;
use Spatie\Permission\PermissionRegistrar;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BootstrapRegistry::class);
    }

    public function boot(): void
    {
        $this->registerBootstrappers();

        // Inbound messaging webhook limiter. The endpoint is public by
        // necessity (the carrier has to reach it) and its real defence
        // is per-provider signature verification — this only bounds the
        // blast radius of a malfunctioning provider or a leaked secret,
        // and keeps a message flood off the Horizon queue that
        // telephony shares.
        //
        // Keyed per source IP so one misbehaving carrier POP can't
        // throttle a different carrier's traffic.
        RateLimiter::for('messaging-inbound', fn (Request $request) => Limit::perMinute(
            (int) config('messaging.inbound_rate_limit', 300),
        )->by($request->ip() ?: 'unknown'));

        // Reverb connection details (app key, browser-facing host/port
        // derived from APP_URL) must be injected at request time, not
        // baked in at Vite build time — Orbital ships as a single
        // distributable image to many customers, each with a different
        // host and Reverb key. Registered once, globally, for all three
        // Filament panels (admin/operator/portal) rather than per-panel.
        // HEAD_START so the runtime global is set before each panel's
        // HEAD_END app.js (a deferred module) executes.
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_START,
            fn (): string => view('partials.echo-config')->render(),
        );

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
        // `hasAnyPlatformRole` so client-only users (who don't have
        // an availability state at all) are a cheap no-op.
        Event::listen(Login::class, ResetAvailabilityOnLogin::class);

        // OIDC clients for the in-platform control panels (pgAdmin,
        // MinIO Console, future tools) are first-party — they're
        // infra we own, not third-party apps that need explicit
        // user consent. `FirstPartyClient` overrides
        // `skipsAuthorization` to return true for our registered
        // client names, so the consent step is skipped entirely.
        Passport::useClientModel(FirstPartyClient::class);

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
        // be on a client's allow list. Logs critical if violated.
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
        $registry = $this->app->make(BootstrapRegistry::class);

        $registry
            ->register($this->app->make(PgvectorBootstrapper::class))
            ->register($this->app->make(S3BucketBootstrapper::class))
            ->register($this->app->make(AsteriskBootstrapper::class))
            ->register($this->app->make(LiveKitBootstrapper::class))
            ->register($this->app->make(IcecastBootstrapper::class))
            ->register($this->app->make(OllamaBootstrapper::class))
            // SSO secrets — generates shared secrets for the
            // Redis Commander JWT + Grafana reverse-proxy trust
            // chain, and creates OAuth2 clients for pgAdmin and
            // MinIO Console OIDC. Registered after the services
            // whose containers consume the resulting .env values
            // so the admin's first install flow is: pgvector →
            // MinIO → Asterisk → ... → SSO secrets → restart.
            ->register($this->app->make(SsoSecretsBootstrapper::class));
    }

    /**
     * Defensive check: the client_permission_grants table must never contain
     * any of the PLATFORM_ONLY permissions. If it does, something has bypassed
     * the gatekeeper — log loudly.
     */
    protected function assertNoPlatformPermissionsInTenantGrants(): void
    {
        // The check is purely defensive logging. Wrap the whole body
        // so any boot-time DB unavailability (CI image build with no
        // DB, fresh install before migrations, transient connection
        // loss) is a silent no-op rather than crashing app boot.
        try {
            if (! Schema::hasTable('client_permission_grants') || ! Schema::hasTable('permissions')) {
                return;
            }

            $count = DB::table('client_permission_grants as g')
                ->join('permissions as p', 'p.id', '=', 'g.permission_id')
                ->whereIn('p.name', ClientPermissionGatekeeper::PLATFORM_ONLY)
                ->count();

            if ($count > 0) {
                Log::critical('Platform-only permissions found in client_permission_grants', [
                    'count' => $count,
                ]);
            }
        } catch (\Throwable $e) {
            // Boot-time DB unavailable — skip the check.
        }
    }
}
