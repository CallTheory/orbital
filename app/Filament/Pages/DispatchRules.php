<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

/**
 * Placeholder page for platform-level dispatch rules — the cross-
 * client routing logic that decides how inbound calls/emails/etc.
 * find their intake goal, flow, or operator queue.
 *
 * Currently a stub. Firm up the shape (operator-group matching,
 * skill routing, time-of-day overrides, client-specific overrides)
 * and then promote to a real resource backed by a dispatch_rules
 * table.
 */
class DispatchRules extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|UnitEnum|null $navigationGroup = 'Workflow';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Dispatch Rules';

    protected static ?string $title = 'Dispatch Rules';

    protected ?string $subheading = 'Platform-wide routing rules that decide where incoming work lands.';

    protected static ?string $slug = 'dispatch-rules';

    protected string $view = 'filament.pages.dispatch-rules';

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }
}
