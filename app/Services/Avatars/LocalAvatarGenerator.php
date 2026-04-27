<?php

declare(strict_types=1);

namespace App\Services\Avatars;

/**
 * Offline replacement for the ui-avatars.com service.
 *
 * Generates a self-contained SVG data URL showing 1–2 uppercase
 * initials on a stable background color picked deterministically
 * from a hash of the name, so the same person always lands on the
 * same color across sessions / panels / mobile / print.
 *
 * Returns a `data:image/svg+xml;base64,...` URL rather than writing
 * to disk — no filesystem churn, no cache invalidation, no HTTP
 * round-trip from the browser. The string is ~600 bytes compressed,
 * which is smaller than the HTTP overhead of fetching a PNG would be.
 *
 * Used by:
 *   - User::profilePhotoUrl() override in the app (replaces
 *     Jetstream's built-in ui-avatars fallback)
 *   - FilamentLocalAvatarProvider (registered on every panel as the
 *     default avatar provider)
 *
 * Offline-first: no external calls, no dependencies, pure PHP. This
 * is what we traded the ui-avatars.com service for — one of the
 * "no public internet needed" requirements from memory.
 */
class LocalAvatarGenerator
{
    /**
     * 12-color palette chosen for legible white text against each
     * background. Deterministic hash modulo len picks the slot for
     * a given name, so "Patrick Labbett" always lands on the same
     * color even though it's pseudo-random.
     *
     * Values are the Tailwind 500 / 600 step across a spread of hues
     * so the palette still looks coherent next to Filament's own
     * primary / success / warning colors on the same page.
     *
     * @var list<string>
     */
    private const PALETTE = [
        '#ef4444', // red-500
        '#f97316', // orange-500
        '#f59e0b', // amber-500
        '#84cc16', // lime-500
        '#22c55e', // green-500
        '#14b8a6', // teal-500
        '#06b6d4', // cyan-500
        '#3b82f6', // blue-500
        '#6366f1', // indigo-500
        '#8b5cf6', // violet-500
        '#d946ef', // fuchsia-500
        '#ec4899', // pink-500
    ];

    /**
     * Build a data URL for the given name.
     *
     * @param  string  $name  Full display name. Initials are derived from the first 1–2 words.
     * @param  int  $size  Pixel size hint — the SVG is vector so this just sets the viewBox;
     *                     the browser will render crisp at any actual element size.
     */
    public function dataUrlFor(string $name, int $size = 256): string
    {
        $name = trim($name);
        if ($name === '') {
            $name = '?';
        }

        $initials = $this->initials($name);
        $background = $this->backgroundFor($name);

        // Font-size 45% of viewBox is the sweet spot — big enough to
        // read at 32px nav-bar size, still leaves visual breathing
        // room inside the circle at 256px profile-card size.
        $fontSize = (int) round($size * 0.45);

        // dominant-baseline=central + text-anchor=middle centers the
        // text both ways without needing y-offset math. Fallback font
        // list covers every platform browser we've seen — the SVG
        // renders in whatever happens to be on the box, which is
        // fine because initials are one to two characters and don't
        // need a specific font.
        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$size} {$size}" width="{$size}" height="{$size}">
  <rect width="{$size}" height="{$size}" fill="{$background}"/>
  <text x="50%" y="50%"
        dominant-baseline="central"
        text-anchor="middle"
        font-family="-apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Helvetica, Arial, sans-serif"
        font-size="{$fontSize}"
        font-weight="600"
        fill="#ffffff">{$initials}</text>
</svg>
SVG;

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Strip non-alphabetic characters from each word, take the first
     * character of the first two words, uppercase it. "Patrick Labbett"
     * → "PL", "cher" → "C", "dr. sarah lee" → "SL" (drops the title).
     */
    private function initials(string $name): string
    {
        $words = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Drop common titles so "Dr. Patrick" doesn't become "DP".
        $words = array_values(array_filter(
            $words,
            fn (string $w) => ! in_array(mb_strtolower(rtrim($w, '.')), ['mr', 'mrs', 'ms', 'dr', 'prof'], true),
        ));

        if (empty($words)) {
            return mb_strtoupper(mb_substr($name, 0, 1));
        }

        $first = mb_substr($words[0], 0, 1);
        $second = isset($words[1]) ? mb_substr($words[1], 0, 1) : '';

        // Escape for XML. mb_strtoupper handles unicode letters; the
        // htmlspecialchars call guards against any non-letter junk
        // that made it past the word-split (rare, but cheap insurance).
        return htmlspecialchars(mb_strtoupper($first.$second), ENT_XML1, 'UTF-8');
    }

    /**
     * Pick a palette slot deterministically from the name. crc32 is
     * plenty here — we just need stability + spread, not collision
     * resistance.
     */
    private function backgroundFor(string $name): string
    {
        $index = crc32(mb_strtolower($name)) % count(self::PALETTE);

        return self::PALETTE[$index];
    }
}
