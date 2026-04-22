<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Tiny resolver for brand image paths. The registry stores image
 * uploads as S3 disk paths (e.g. `branding/platform/abc.png`); the
 * panels and login views want a browser-reachable URL.
 *
 * Logos come in light + dark variants so the UI can swap based on
 * the viewer's color mode. Rules:
 *   - Uploaded value wins if present.
 *   - If only one variant is uploaded, it's used in both modes
 *     (rather than mixing a custom upload with the Orbital default).
 *   - If neither is uploaded, fall back to the shipped
 *     /images/orbital-logo-{light,dark}.png defaults.
 *
 * Favicons are single-variant — browsers don't swap per mode, so we
 * don't collect two uploads for them.
 */
final class Branding
{
    /**
     * Resolve a config key (e.g. `orbital.platform_logo_light`) to a
     * public URL, or null if nothing's uploaded at that key.
     */
    public static function url(string $configKey): ?string
    {
        $path = config($configKey);
        if (! is_string($path) || $path === '') {
            return null;
        }

        return Storage::disk('s3')->url($path);
    }

    // ─── Platform logo ────────────────────────────────────────────

    public static function platformLogoLightUrl(): string
    {
        return self::url('orbital.platform_logo_light')
            ?? self::url('orbital.platform_logo_dark')
            ?? asset('images/orbital-logo-light.png');
    }

    public static function platformLogoDarkUrl(): string
    {
        return self::url('orbital.platform_logo_dark')
            ?? self::url('orbital.platform_logo_light')
            ?? asset('images/orbital-logo-dark.png');
    }

    public static function platformFaviconUrl(): ?string
    {
        return self::url('orbital.platform_favicon')
            ?? asset('images/orbital-icon.png');
    }

    // ─── Portal logo ──────────────────────────────────────────────

    public static function portalLogoLightUrl(): string
    {
        return self::url('orbital.portal_logo_light')
            ?? self::url('orbital.portal_logo_dark')
            ?? asset('images/orbital-logo-light.png');
    }

    public static function portalLogoDarkUrl(): string
    {
        return self::url('orbital.portal_logo_dark')
            ?? self::url('orbital.portal_logo_light')
            ?? asset('images/orbital-logo-dark.png');
    }

    public static function portalFaviconUrl(): ?string
    {
        return self::url('orbital.portal_favicon')
            ?? asset('images/orbital-icon.png');
    }
}
