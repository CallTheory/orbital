<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Settings\SettingsRegistry;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Walks SettingsRegistry on boot and applies every persisted setting
 * on top of Laravel's runtime config. After this runs,
 * `config('mail.from.address')`, `config('orbital.admin_primary_color')`,
 * `config('telephony.asterisk.ami.host')` etc. transparently reflect
 * whatever the operator has saved in the platform_settings table —
 * the .env file becomes the fallback for anything not overridden.
 *
 * **Timing matters.** Filament's internal service providers resolve
 * `PanelRegistry` during their own boot pass, which triggers each
 * app PanelProvider's resolving callback and runs `panel()`. That's
 * when panel brand name, brand logo, primary color, and favicon get
 * baked into the Panel instance. If we apply overrides in this
 * provider's `boot()`, we're racing Filament — Laravel orders
 * `boot()` calls by provider registration order, and package
 * providers typically boot before app providers even if the app
 * provider is listed first in `bootstrap/providers.php`.
 *
 * Fix: wire the override application into `$app->booting()` from
 * our `register()` method. Laravel fires every `booting` callback
 * at the START of the boot phase — before the first provider's
 * `boot()` runs. So config is hydrated with DB overrides before
 * any panel can resolve.
 *
 * Subtlety: at `booting()` time the Eloquent connection resolver
 * isn't set yet (that's wired in `DatabaseServiceProvider::boot()`,
 * which hasn't run), so calling `PlatformSetting::query()` would
 * crash with "Call to a member function connection() on null."
 * The `db` singleton binding (registered in `register()`) is
 * available though, so we read via `DB::table(...)` directly —
 * bypassing Eloquent and also the cache layer, which depends on
 * services that aren't guaranteed to be ready this early either.
 *
 * Two important caveats:
 *   1. Long-running processes (Horizon workers, Reverb, LiveKit
 *      agent worker) only see overrides that were present when
 *      they booted. Restart them after editing infrastructure
 *      settings.
 *   2. `config:cache` snapshots merged config to disk and skips
 *      provider boot for config reads. If you use `config:cache`
 *      in production, run `config:clear` after editing settings
 *      or the cached file will keep serving stale values.
 */
class RuntimeConfigOverrideProvider extends ServiceProvider
{
    public function register(): void
    {
        // Fires at the start of the boot phase, before any
        // ServiceProvider::boot() method runs — including Filament's.
        $this->app->booting(function (): void {
            $this->applyOverrides();
        });
    }

    protected function applyOverrides(): void
    {
        // Defensive: during initial migrate, the table doesn't exist
        // yet. We don't want to crash artisan migrate just because we
        // tried to read settings before they could exist.
        try {
            if (! Schema::hasTable('platform_settings')) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        try {
            // Use DB::table(), NOT PlatformSetting::query(). Booting
            // callbacks fire before Eloquent's connection resolver is
            // initialized (that happens in DatabaseServiceProvider::
            // boot()), so any Eloquent query this early throws "Call
            // to a member function connection() on null." The `db`
            // singleton is already registered though, so the query
            // builder works fine.
            $rows = DB::table('platform_settings')->pluck('value', 'key');
        } catch (Throwable) {
            return;
        }

        $registry = SettingsRegistry::all();

        foreach ($rows as $key => $raw) {
            if ($raw === null || $raw === '') {
                continue;
            }

            $def = $registry[$key] ?? null;
            if (! $def) {
                continue;
            }

            $value = $this->decode($key, $raw);
            if ($value === null || $value === '') {
                continue;
            }

            // A registry entry can target a single Laravel config key
            // (`config_key`) or fan out to several at once
            // (`config_keys`). Fan-out is used for settings like
            // LOG_LEVEL where the real config lives under N channel
            // entries instead of one root.
            $targets = $def['config_keys']
                ?? (isset($def['config_key']) ? [$def['config_key']] : []);
            foreach ($targets as $target) {
                config()->set($target, $value);
            }
        }
    }

    /**
     * Decode a raw platform_settings.value column into the plain
     * value. The column is cast to `array` on the Eloquent model so
     * values are stored JSON-encoded ("Indigo" not Indigo); since
     * we're bypassing Eloquent we JSON-decode ourselves. Secrets are
     * double-encoded — JSON-wrapped *and* crypt-encrypted — so we
     * unwrap JSON first and then decrypt when the registry flags the
     * key as secret.
     */
    protected function decode(string $key, mixed $raw): mixed
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $raw = $decoded;
            }
        }

        if ($raw !== null && is_string($raw) && SettingsRegistry::isSecret($key)) {
            try {
                return Crypt::decryptString($raw);
            } catch (DecryptException) {
                return null;
            }
        }

        return $raw;
    }
}
