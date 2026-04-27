<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\PlatformSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Read/write access to platform-wide DB-backed settings.
 *
 * The database is the source of truth. A cache layer sits in front of every
 * read so config lookups are fast (Valkey-backed). Writes invalidate the
 * cached key.
 *
 * Settings flagged as secret in {@see SettingsRegistry} are encrypted at
 * rest using Laravel's app key. The encryption is transparent — callers
 * always work with plaintext.
 */
class PlatformSettingsRepository
{
    protected const CACHE_PREFIX = 'platform_setting:';

    protected const CACHE_TTL_SECONDS = 300;

    protected const ALL_CACHE_KEY = 'platform_settings:all';

    /**
     * Get a single setting by key, with optional default. Secrets are
     * decrypted automatically.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $cached = Cache::remember(
            self::CACHE_PREFIX.$key,
            self::CACHE_TTL_SECONDS,
            fn () => PlatformSetting::where('key', $key)->first()?->value,
        );

        if ($cached === null) {
            return $default;
        }

        return $this->decode($key, $cached) ?? $default;
    }

    /**
     * Get many settings at once, indexed by key.
     *
     * @param  array<int, string>  $keys
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    public function many(array $keys, array $defaults = []): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key, $defaults[$key] ?? null);
        }

        return $out;
    }

    /**
     * Bulk-load every persisted setting in a single query and return it as
     * a key → decoded-value map. Used by RuntimeConfigOverrideProvider on
     * boot to avoid N+1 lookups when applying every override.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $rows = Cache::remember(
            self::ALL_CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn () => PlatformSetting::query()->pluck('value', 'key')->all(),
        );

        $out = [];
        foreach ($rows as $key => $raw) {
            $out[$key] = $this->decode($key, $raw);
        }

        return $out;
    }

    /**
     * Persist a value under $key. Secret keys are encrypted before storage.
     * Audits the writer if a user is authed.
     */
    public function set(string $key, mixed $value): void
    {
        $stored = $this->encode($key, $value);

        PlatformSetting::updateOrCreate(
            ['key' => $key],
            [
                'value' => $stored,
                'updated_by' => auth()->id(),
            ],
        );

        Cache::forget(self::CACHE_PREFIX.$key);
        Cache::forget(self::ALL_CACHE_KEY);
    }

    /**
     * Bulk write — wraps multiple set() calls.
     *
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }
        Cache::forget(self::ALL_CACHE_KEY);
    }

    /**
     * Drop a setting entirely (returns to the default).
     */
    public function forget(string $key): void
    {
        PlatformSetting::where('key', $key)->delete();
        Cache::forget(self::CACHE_PREFIX.$key);
        Cache::forget(self::ALL_CACHE_KEY);
    }

    /**
     * Forget every cache entry related to platform settings.
     */
    public function flushCache(): void
    {
        Cache::forget(self::ALL_CACHE_KEY);
        // Per-key entries expire on their own TTL; for an immediate flush
        // we rely on the bulk forget that surrounds writes via set().
    }

    /**
     * Encode a value before storage. Secret values are encrypted; everything
     * else is left as-is (the model's `array` cast handles JSON encoding).
     */
    protected function encode(string $key, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (SettingsRegistry::isSecret($key)) {
            return Crypt::encryptString((string) $value);
        }

        return $value;
    }

    /**
     * Decode a value read from storage. Secret values are decrypted; if a
     * decryption error occurs (e.g. the app key rotated), the field reads
     * as null instead of throwing — the operator can re-enter the value.
     */
    protected function decode(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (SettingsRegistry::isSecret($key) && is_string($value)) {
            try {
                return Crypt::decryptString($value);
            } catch (DecryptException) {
                return null;
            }
        }

        return $value;
    }
}
