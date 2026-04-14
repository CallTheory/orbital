<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use App\Models\CallLog;
use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use UnitEnum;

/**
 * Tenant user's home page on the portal. Shows a small summary of
 * recent call activity so they land on something useful; the full
 * call history lives on {@see CallHistory}.
 *
 * Extends Filament's base Dashboard so the page auto-registers at the
 * panel root (`/portal`) instead of getting its own slug.
 *
 * Queries bypass the BelongsToTeam global scope (we don't rely on it
 * to be set correctly for the portal context) and filter explicitly
 * on `current_team_id` for defense in depth.
 */
class Dashboard extends BaseDashboard
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected static string|UnitEnum|null $navigationGroup = 'Activity';

    protected static ?string $navigationLabel = 'Overview';

    protected static ?string $title = 'Overview';

    protected static ?int $navigationSort = -10;

    protected string $view = 'filament.portal.pages.dashboard';

    public array $recentCalls = [];

    public int $callsTodayCount = 0;

    public string $tenantName = '';

    public function mount(): void
    {
        $user = auth()->user();
        $teamId = (int) ($user->current_team_id ?? 0);
        $this->tenantName = $user->currentTeam?->name ?? 'Unknown Tenant';

        $this->recentCalls = CallLog::query()
            ->where('team_id', $teamId)
            ->orderByDesc('started_at')
            ->limit(5)
            ->get(['id', 'direction', 'from_number', 'to_number', 'status', 'duration_seconds', 'started_at'])
            ->map(fn ($row) => [
                'id' => $row->id,
                'direction' => $row->direction,
                'from_number' => $row->from_number,
                'to_number' => $row->to_number,
                'status' => $row->status,
                'duration_seconds' => $row->duration_seconds,
                'started_at' => optional($row->started_at)->format('M j, g:i a'),
            ])
            ->all();

        $this->callsTodayCount = CallLog::query()
            ->where('team_id', $teamId)
            ->whereDate('started_at', today())
            ->count();
    }
}
