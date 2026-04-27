<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\Bootstrap\BootstrapRegistry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use UnitEnum;

/**
 * System Setup — the web counterpart to `artisan orbital:bootstrap`.
 *
 * Shows every registered Bootstrapper as a card with its current
 * status and an "Install / Re-run" button. Super-admin only. Sits
 * under the Platform nav group next to Platform Settings so new
 * installs can click-to-bootstrap right after logging in.
 */
class SystemSetup extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Setup';

    protected static ?string $title = 'Setup';

    protected ?string $subheading = 'Installation runners for services we depend on.';

    protected static ?string $slug = 'setup';

    protected string $view = 'filament.pages.system-setup';

    /** @var array<string, array<string, mixed>> */
    public array $reports = [];

    public function mount(): void
    {
        $this->refreshStatus();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function refreshStatus(): void
    {
        $registry = app(BootstrapRegistry::class);
        $reports = $registry->statusAll();

        $this->reports = [];
        foreach ($registry->all() as $key => $bootstrapper) {
            $report = $reports[$key] ?? null;
            if (! $report) {
                continue;
            }
            $this->reports[$key] = array_merge($report->toArray(), [
                'name' => $bootstrapper->name(),
                'description' => $bootstrapper->description(),
                'icon' => $bootstrapper->icon(),
                'optional' => $bootstrapper->isOptional(),
            ]);
        }
    }

    public function installOne(string $key): void
    {
        $registry = app(BootstrapRegistry::class);
        $b = $registry->get($key);
        if (! $b) {
            Notification::make()->title('Unknown bootstrapper')->body($key)->danger()->send();

            return;
        }

        try {
            $b->install();
            Notification::make()->title($b->name().' installed')->success()->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title($b->name().' install failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }

        $this->refreshStatus();
    }

    public function installAll(): void
    {
        try {
            app(BootstrapRegistry::class)->runAll();
            Notification::make()
                ->title('Bootstrap complete')
                ->body('All services have been initialized.')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Bootstrap failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }

        $this->refreshStatus();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh status')
                ->icon('heroicon-o-arrow-path')
                ->action('refreshStatus'),
            Action::make('installAll')
                ->label('Install all')
                ->icon('heroicon-o-rocket-launch')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('Run every bootstrapper in order. Idempotent — safe to re-run on an already-configured install.')
                ->action('installAll'),
        ];
    }
}
