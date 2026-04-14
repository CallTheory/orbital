<?php

declare(strict_types=1);

namespace App\Filament\Operator\Pages;

use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use UnitEnum;

/**
 * The operator panel's default landing page — mirrors the pattern in
 * AdminPanelProvider's custom Dashboard. Extends Filament's base
 * Dashboard class so it auto-registers at the panel root (`/operator`)
 * instead of getting its own slug like a normal Page would.
 *
 * Hosts the compiled intake flow viewer alongside queue status and
 * live call context. The Livewire `compiled-flow-viewer` component
 * reads whichever intake flow is bound to the active call; when no
 * call is active it falls back to the first tenant persona so the
 * operator has something to see while the softphone is idle.
 */
class Workspace extends BaseDashboard
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected static string|UnitEnum|null $navigationGroup = 'Workspace';

    protected static ?string $navigationLabel = 'Workspace';

    protected static ?string $title = 'Workspace';

    protected static ?int $navigationSort = -10;

    protected string $view = 'filament.operator.pages.workspace';
}
