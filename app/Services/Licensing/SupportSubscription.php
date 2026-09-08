<?php

declare(strict_types=1);

namespace App\Services\Licensing;

use App\Services\Settings\PlatformSettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Support subscription state.
 *
 * READ THIS BEFORE ADDING A CALLER.
 *
 * Orbital is AGPL-3.0 and 100% of the product is free to self-host. This
 * class exists to answer exactly one question — "does this installation
 * have a paid support relationship with its vendor?" — and the only things
 * allowed to branch on the answer are SUPPORT SURFACES:
 *
 *   - in-app support ticket submission
 *   - the signed update channel (pre-built images)
 *   - a "supported until <date>" line on the About page
 *
 * A product feature must never call this class. No queue, no channel, no
 * seat count, no AI provider, no HA control may consult it. An expired,
 * absent, or malformed key leaves the software behaving identically to a
 * fully subscribed one. There is a test that asserts this —
 * SourceOfferTest::test_support_state_does_not_change_product_behaviour.
 * If you find yourself wanting to gate a feature here, the answer is no,
 * and the commitment made in LICENSING.md is why.
 *
 * Key format: "<base64url payload>.<base64url ed25519 signature>", where
 * the payload is JSON. Verification is entirely offline against the public
 * key in config('orbital.support.public_key') — Orbital never phones home,
 * and an air-gapped install must behave the same as a connected one.
 */
class SupportSubscription
{
    /** platform_settings key holding the raw subscription key. */
    public const SETTING_KEY = 'support.subscription_key';

    /**
     * Per-instance memo. `false` means "not resolved yet" so that a
     * genuine null (no subscription) is still cached.
     *
     * @var array{tier: string, licensee: ?string, expires_at: ?CarbonImmutable}|null|false
     */
    private array|null|false $cachedClaims = false;

    public function __construct(
        private readonly PlatformSettingsRepository $settings,
    ) {}

    /**
     * Is there a valid, unexpired subscription?
     */
    public function isActive(): bool
    {
        $claims = $this->claims();

        if ($claims === null) {
            return false;
        }

        return $claims['expires_at'] === null
            || $claims['expires_at']->isFuture();
    }

    /**
     * Support tier name ("standard", "priority", …) or null when there is
     * no valid subscription.
     */
    public function tier(): ?string
    {
        return $this->isActive() ? $this->claims()['tier'] : null;
    }

    public function expiresAt(): ?CarbonImmutable
    {
        return $this->claims()['expires_at'] ?? null;
    }

    /**
     * Who the subscription was issued to — shown on the About page so an
     * admin can tell at a glance whether they pasted the right key.
     */
    public function licensee(): ?string
    {
        return $this->claims()['licensee'] ?? null;
    }

    /**
     * A key that verified but whose expiry has passed. Worth surfacing
     * distinctly: "your support ran out" is a different message from
     * "you never had support", and only one of them is a renewal prompt.
     */
    public function isExpired(): bool
    {
        $claims = $this->claims();

        return $claims !== null
            && $claims['expires_at'] !== null
            && $claims['expires_at']->isPast();
    }

    /**
     * Human-readable state for the About page.
     */
    public function statusLabel(): string
    {
        if ($this->isActive()) {
            $expires = $this->expiresAt();

            return $expires === null
                ? 'Active'
                : 'Active until '.$expires->toFormattedDateString();
        }

        if ($this->isExpired()) {
            return 'Expired '.$this->expiresAt()?->toFormattedDateString();
        }

        return 'None — community support';
    }

    /**
     * Store a key after verifying it. Returns null on success or a
     * human-readable reason on failure, so the caller can surface it
     * without needing to know anything about signatures.
     */
    public function store(string $key): ?string
    {
        $key = trim($key);

        if ($key === '') {
            $this->settings->forget(self::SETTING_KEY);
            $this->flushCache();

            return null;
        }

        $claims = $this->verify($key);

        if ($claims === null) {
            return 'That key could not be verified. Check for a truncated paste, or contact support for a replacement.';
        }

        $this->settings->set(self::SETTING_KEY, $key);
        $this->flushCache();

        return null;
    }

    /**
     * Verified claims from the stored key, or null when there is no key or
     * it fails verification.
     *
     * @return array{tier: string, licensee: ?string, expires_at: ?CarbonImmutable}|null
     */
    private function claims(): ?array
    {
        if ($this->cachedClaims !== false) {
            return $this->cachedClaims;
        }

        try {
            $key = $this->settings->get(self::SETTING_KEY);
        } catch (\Throwable) {
            // Settings live in the database, and `orbital:about` is
            // exactly the command someone runs when the database is the
            // thing that's broken. Degrade to "no subscription" rather
            // than turning a triage tool into another stack trace.
            return $this->cachedClaims = null;
        }

        $this->cachedClaims = is_string($key) && $key !== ''
            ? $this->verify($key)
            : null;

        return $this->cachedClaims;
    }

    private function flushCache(): void
    {
        $this->cachedClaims = false;
    }

    /**
     * Verify a key's signature and decode its claims.
     *
     * Every failure path returns null. Nothing here throws, and nothing
     * here logs the key itself.
     *
     * @return array{tier: string, licensee: ?string, expires_at: ?CarbonImmutable}|null
     */
    private function verify(string $key): ?array
    {
        $publicKey = config('orbital.support.public_key');

        if (! is_string($publicKey) || $publicKey === '') {
            // No issuer configured — a source build with no vendor
            // relationship. Not an error, just no subscription.
            return null;
        }

        $publicKeyRaw = base64_decode($publicKey, true);

        if ($publicKeyRaw === false || strlen($publicKeyRaw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            Log::warning('Support subscription public key is not a valid Ed25519 key; ignoring any stored subscription.');

            return null;
        }

        if (substr_count($key, '.') !== 1) {
            return null;
        }

        [$payloadPart, $signaturePart] = explode('.', $key, 2);

        $payload = self::base64UrlDecode($payloadPart);
        $signature = self::base64UrlDecode($signaturePart);

        if ($payload === null || $signature === null) {
            return null;
        }

        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return null;
        }

        try {
            $valid = sodium_crypto_sign_verify_detached($signature, $payload, $publicKeyRaw);
        } catch (\SodiumException) {
            return null;
        }

        if (! $valid) {
            return null;
        }

        $claims = json_decode($payload, true);

        if (! is_array($claims)) {
            return null;
        }

        $expiresAt = null;

        if (isset($claims['expires_at']) && is_string($claims['expires_at'])) {
            try {
                $expiresAt = CarbonImmutable::parse($claims['expires_at']);
            } catch (\Throwable) {
                // A signed key with an unparseable date is a malformed key.
                return null;
            }
        }

        return [
            'tier' => is_string($claims['tier'] ?? null) ? $claims['tier'] : 'standard',
            'licensee' => is_string($claims['licensee'] ?? null) ? $claims['licensee'] : null,
            'expires_at' => $expiresAt,
        ];
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
