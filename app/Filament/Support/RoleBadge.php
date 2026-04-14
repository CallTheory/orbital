<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Illuminate\Support\HtmlString;
use Spatie\Permission\Models\Role;

/**
 * Renders a colored pill badge for a Spatie role using the role's stored
 * color (set via the Roles resource color picker).
 *
 * Used in any Filament table column or page that displays a role name —
 * Staff list, Roles list, future audit log, etc. Centralizing keeps the
 * styling consistent everywhere.
 */
class RoleBadge
{
    public const FALLBACK_COLOR = '#6b7280'; // gray-500

    /**
     * Render a badge by role name. Looks up the role's color from the DB.
     * Returns an HtmlString safe for use with Filament's html() column.
     */
    public static function forName(?string $roleName): HtmlString
    {
        if (! $roleName) {
            return new HtmlString('<span style="color: rgb(156 163 175); font-style: italic;">—</span>');
        }

        $color = Role::query()
            ->where('name', $roleName)
            ->value('color') ?: self::FALLBACK_COLOR;

        return self::render($roleName, $color);
    }

    /**
     * Render a badge with an explicit hex color. Useful when you already
     * have the Role model in hand and want to skip a query.
     */
    public static function render(string $label, string $hex): HtmlString
    {
        $hex = self::normalizeHex($hex);

        // Translucent background and border, full-saturation foreground.
        $bg = $hex.'1a';     // ~10% alpha
        $border = $hex.'66'; // ~40% alpha

        return new HtmlString(sprintf(
            '<span style="display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 9999px; '.
            'font-size: 0.75rem; font-weight: 500; line-height: 1.25rem; '.
            'background-color: %s; color: %s; border: 1px solid %s;">%s</span>',
            e($bg),
            e($hex),
            e($border),
            e($label),
        ));
    }

    /**
     * Coerce input to a 6-digit hex prefixed with #. Falls back to gray
     * if the value is malformed.
     */
    protected static function normalizeHex(?string $hex): string
    {
        if (! $hex) return self::FALLBACK_COLOR;

        $hex = ltrim(trim($hex), '#');

        // Expand 3-char shorthand to 6-char
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return self::FALLBACK_COLOR;
        }

        return '#'.strtolower($hex);
    }
}
