<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Release identity — what version of Orbital is this, under what license,
 * and where is its source.
 *
 * This is the single place the AGPL section 13 answer is assembled. The
 * panel footers, the About page, /source, /api/version, and
 * `php artisan orbital:about` all read from here so they can never drift
 * apart and start telling users different stories about what they're
 * running.
 *
 * Deliberately dependency-free and non-throwing: a §13 offer that 500s
 * because the database is down is not an offer.
 */
final class Release
{
    /**
     * Human-readable version string, e.g. "1.4.2" or "dev".
     */
    public static function version(): string
    {
        $version = (string) config('orbital.version', 'dev');

        return $version !== '' ? $version : 'dev';
    }

    /**
     * Full commit SHA baked in at image build time, or null for a source
     * checkout that was never built into an image.
     */
    public static function commit(): ?string
    {
        $commit = config('orbital.commit');

        return is_string($commit) && $commit !== '' ? $commit : null;
    }

    /**
     * First 12 characters of the commit — enough to identify it, short
     * enough to display next to a version number.
     */
    public static function shortCommit(): ?string
    {
        $commit = self::commit();

        return $commit === null ? null : substr($commit, 0, 12);
    }

    public static function channel(): string
    {
        return (string) config('orbital.release_channel', 'dev');
    }

    /**
     * "1.4.2 (a1b2c3d4e5f6)" — the string a support engineer wants to see
     * first in any bug report.
     */
    public static function display(): string
    {
        $short = self::shortCommit();

        return $short === null
            ? self::version()
            : self::version().' ('.$short.')';
    }

    public static function licenseSpdx(): string
    {
        return (string) config('orbital.license.spdx', 'AGPL-3.0-only');
    }

    public static function licenseName(): string
    {
        return (string) config('orbital.license.name', 'GNU Affero General Public License v3.0');
    }

    public static function licenseUrl(): string
    {
        return (string) config('orbital.license.url', 'https://www.gnu.org/licenses/agpl-3.0.html');
    }

    /**
     * Where the corresponding source for THIS running build lives.
     *
     * Operators who fork and modify Orbital must repoint this at their own
     * repository (ORBITAL_SOURCE_URL) — that is the whole substance of the
     * section 13 obligation.
     */
    public static function sourceUrl(): string
    {
        $url = (string) config('orbital.source_url', '');

        return $url !== '' ? $url : 'https://github.com/calltheory/orbital';
    }

    /**
     * Deep link to the exact commit when we know it and the source host
     * understands /tree/<sha> URLs (GitHub, Gitea, Forgejo all do).
     * Falls back to the repository root.
     */
    public static function sourceUrlForCommit(): string
    {
        $base = rtrim(self::sourceUrl(), '/');
        $commit = self::commit();

        if ($commit === null || ! str_contains($base, '://')) {
            return $base;
        }

        return $base.'/tree/'.$commit;
    }

    /**
     * The payload behind /api/version and `orbital:about`.
     *
     * @return array<string, mixed>
     */
    public static function toArray(): array
    {
        return [
            'name' => 'Orbital',
            'version' => self::version(),
            'commit' => self::commit(),
            'channel' => self::channel(),
            'license' => self::licenseSpdx(),
            'license_url' => self::licenseUrl(),
            'source_url' => self::sourceUrl(),
        ];
    }
}
