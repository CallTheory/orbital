<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Settings\PlatformSettingsRepository;
use App\Services\Settings\SettingsRegistry;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Walks SettingsRegistry on boot and applies every persisted setting on
 * top of Laravel's runtime config. After this provider boots,
 * `config('mail.from.address')`, `config('services.openai.api_key')`,
 * `config('telephony.asterisk.ami.host')` etc. transparently reflect
 * whatever the operator has saved in the platform_settings table — the
 * .env file becomes the fallback for anything not overridden.
 *
 * Two important caveats:
 *
 *   1. Long-running processes (queue workers, Horizon, Octane, the LiveKit
 *      agent worker) only see overrides that were present when they booted.
 *      Restart those processes after editing infrastructure settings.
 *
 *   2. `config:cache` snapshots the merged config to disk and skips
 *      provider boot for config reads. If you're using `config:cache` in
 *      production, run `config:clear` after editing settings, or the
 *      cached file will keep serving stale values.
 */
class RuntimeConfigOverrideProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Defensive: during initial migrate, the table doesn't exist yet.
        // We don't want to crash artisan migrate just because we tried to
        // read settings before they could exist.
        try {
            if (! Schema::hasTable('platform_settings')) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        try {
            $repo = app(PlatformSettingsRepository::class);
            $values = $repo->all();
        } catch (Throwable) {
            return;
        }

        $registry = SettingsRegistry::all();

        foreach ($values as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $configKey = $registry[$key]['config_key'] ?? null;
            if (! $configKey) {
                continue;
            }

            config()->set($configKey, $value);
        }
    }
}
