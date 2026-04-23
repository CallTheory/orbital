<?php

declare(strict_types=1);

namespace App\Services\Settings;

/**
 * Known platform setting keys for the telephony domain.
 *
 * Keep these as constants so the Filament form, the dialplan generator,
 * and any future call-routing services all reference the same strings.
 */
final class TelephonySettingsKeys
{
    // ─── Unmatched inbound calls ────────────────────────────────────────
    /** What to do when an inbound DID does not match any client. */
    public const UNMATCHED_ACTION = 'telephony.unmatched.action';
    /** SIP response code used when action = reject. */
    public const UNMATCHED_REJECT_CODE = 'telephony.unmatched.reject_code';
    /** TTS message played when action = play_message. */
    public const UNMATCHED_MESSAGE = 'telephony.unmatched.message';
    /** Client ID to forward to when action = route_to_tenant. */
    public const UNMATCHED_CATCHALL_TENANT_ID = 'telephony.unmatched.catchall_tenant_id';

    // ─── Outage / holding behavior ──────────────────────────────────────
    /** Maximum seconds to hold before falling back. */
    public const OUTAGE_MAX_HOLD_SECONDS = 'telephony.outage.max_hold_seconds';
    /** What to do after max hold time / when normal paths are unreachable. */
    public const OUTAGE_FALLBACK_ACTION = 'telephony.outage.fallback_action';
    /** SIP response code used when fallback_action = reject. */
    public const OUTAGE_REJECT_CODE = 'telephony.outage.reject_code';
    /** TTS message played during outage. */
    public const OUTAGE_MESSAGE = 'telephony.outage.message';
    /** Email to notify whenever outage handling fires (any fallback action). */
    public const OUTAGE_NOTIFY_EMAIL = 'telephony.outage.notify_email';
    /** Minimum minutes between outage notifications to the same email. */
    public const OUTAGE_NOTIFY_COOLDOWN_MINUTES = 'telephony.outage.notify_cooldown_minutes';
    /** Music-on-hold class to play while holding. */
    public const OUTAGE_HOLD_MUSIC = 'telephony.outage.hold_music';

    /**
     * Common SIP rejection codes the platform can return.
     *
     * Different codes have different semantics for upstream carriers:
     *   - 404 / 604 → per-number signals (do NOT trigger trunk failover)
     *   - 503       → per-trunk signal (typically triggers carrier-side failover)
     *   - 480 / 486 → "temporarily unavailable / busy" (may be retried)
     *
     * @return array<int, string>
     */
    /**
     * 4xx-only rejection codes — appropriate for unmatched/per-call rejections
     * where the issue is the specific call, not the server or the entire route.
     * Excludes 5xx (server failures) and 6xx (global failures), which should
     * only be sent when those conditions actually occur.
     *
     * @return array<int, string>
     */
    public static function clientRejectCodeOptions(): array
    {
        return [
            404 => '404 Not Found',
            410 => '410 Gone',
            480 => '480 Temporarily Unavailable',
            484 => '484 Address Incomplete',
            485 => '485 Ambiguous',
            486 => '486 Busy Here',
            488 => '488 Not Acceptable Here',
        ];
    }

    /**
     * Full rejection code list including 5xx and 6xx — appropriate for actual
     * server failures and outage handling.
     *
     * @return array<int, string>
     */
    public static function serverRejectCodeOptions(): array
    {
        return [
            500 => '500 Server Internal Error',
            502 => '502 Bad Gateway',
            503 => '503 Service Unavailable',
            504 => '504 Server Time-out',
            600 => '600 Busy Everywhere',
            603 => '603 Decline',
            604 => '604 Does Not Exist Anywhere',
            606 => '606 Not Acceptable',
        ];
    }

    // ─── Defaults (used when a setting has never been written) ──────────
    public const DEFAULTS = [
        self::UNMATCHED_ACTION => 'reject',
        self::UNMATCHED_REJECT_CODE => 404, // per-number signal
        self::UNMATCHED_MESSAGE => 'We were unable to identify the number you dialed. Please check the number and try again.',
        self::UNMATCHED_CATCHALL_TENANT_ID => null,

        self::OUTAGE_MAX_HOLD_SECONDS => 600, // 10 minutes
        self::OUTAGE_FALLBACK_ACTION => 'voicemail',
        self::OUTAGE_REJECT_CODE => 503, // per-trunk signal — triggers carrier failover
        self::OUTAGE_MESSAGE => 'We are experiencing technical difficulties. Please leave a message after the tone and we will return your call shortly.',
        self::OUTAGE_NOTIFY_EMAIL => null,
        self::OUTAGE_NOTIFY_COOLDOWN_MINUTES => 15,
        self::OUTAGE_HOLD_MUSIC => 'default',
    ];
}
