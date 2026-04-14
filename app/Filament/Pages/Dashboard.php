<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\Health\SystemHealthService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Replaces Filament's stock dashboard with a system status board.
 *
 * Runs every health check on render. Cheap enough to not need polling, but
 * a manual "Refresh" action is provided. Routed at `/` so it's the panel home.
 */
class Dashboard extends BaseDashboard
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $title = 'Dashboard';

    protected static ?int $navigationSort = -10;

    protected string $view = 'filament.pages.dashboard';

    public array $checks = [];

    public array $summary = [];

    public ?string $checkedAt = null;

    public function mount(): void
    {
        $this->loadChecks(useCache: true);
    }

    public function refresh(): void
    {
        \Illuminate\Support\Facades\Cache::forget('system_health:checks');
        $this->loadChecks(useCache: false);
    }

    private function loadChecks(bool $useCache): void
    {
        $service = app(SystemHealthService::class);
        $checks = $service->runAll(useCache: $useCache);

        // Surface problems first: down → warn → ok, stable within each bucket.
        $weight = ['down' => 0, 'warn' => 1, 'ok' => 2];
        usort($checks, fn ($a, $b) => ($weight[$a->status] ?? 99) <=> ($weight[$b->status] ?? 99));

        $this->checks = array_map(fn ($c) => [
            'key' => $c->key,
            'name' => $c->name,
            'category' => $c->category,
            'status' => $c->status,
            'statusLabel' => $c->statusLabel(),
            'color' => $c->color(),
            'message' => $c->message,
            'metrics' => $c->metrics,
            'icon' => $c->icon,
        ], $checks);

        $this->summary = $service->summarize($checks);
        $this->checkedAt = now()->format('M j, Y g:i:s A T');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh')
                ->icon('heroicon-o-arrow-path')
                ->action('refresh'),
        ];
    }
}
