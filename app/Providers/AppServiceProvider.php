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
            ->register($this->app->make(\App\Services\Bootstrap\Bootstrappers\OllamaBootstrapper::class));
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
