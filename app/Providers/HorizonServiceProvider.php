<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * Anyone with the `tooling.horizon` permission (or super_admin) can
     * access the Horizon dashboard. Wire in via Spatie team-less roles.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            if (! $user) {
                return false;
            }
            if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
                return true;
            }

            return method_exists($user, 'hasPermissionTo')
                ? $user->hasPermissionTo('tooling.horizon')
                : false;
        });
    }
}
