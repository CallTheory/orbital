<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use UnitEnum;

/**
 * Tools — one-click runner for a curated list of artisan commands.
 *
 * Super-admin only. Each entry is explicitly allowlisted here, NOT
 * auto-discovered from the command registry: this is a remote
 * execution surface on an admin page, so the set of things it can
 * fire should be greppable in one place.
 *
 * Destructive commands (`migrate:fresh`, `queue:flush`, anything
 * that would wipe data) are deliberately excluded — the blast
 * radius of an accidental click outweighs the convenience. Those
 * stay CLI-only.
 */
class Tools extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-wrench';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Tools';

    protected static ?string $title = 'Tools';

    protected ?string $subheading = 'One-click maintenance commands.';

    protected static ?string $slug = 'tools';

    protected string $view = 'filament.pages.tools';

    /** Last run's captured output, keyed by tool id. */
    public array $lastOutput = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'One-click runners for common maintenance artisan commands.';
    }

    /**
     * The curated tool list. Keep this as an explicit array, not a
     * discovery mechanism — when a new command becomes web-runnable
     * it should show up in code review, not automatically.
     *
     * @return array<int, array{id: string, name: string, description: string, command: string, icon: string, group: string}>
     */
    public function getTools(): array
    {
        return [
            [
                'id' => 'schedule-run',
                'name' => 'Run scheduler tick',
                'description' => 'Fire every due scheduled task immediately, including the heartbeat touched by the system health card.',
                'command' => 'schedule:run',
                'icon' => 'heroicon-o-clock',
                'group' => 'System',
            ],
            [
                'id' => 'orbital-status',
                'name' => 'Orbital status',
                'description' => 'Full system status dump — same data the dashboard cards read, rendered as a CLI report.',
                'command' => 'orbital:status',
                'icon' => 'heroicon-o-heart',
                'group' => 'System',
            ],
            [
                'id' => 'cache-clear',
                'name' => 'Clear application cache',
                'description' => 'Flush the shared application cache (Valkey). Does NOT flush queued jobs or sessions.',
                'command' => 'cache:clear',
                'icon' => 'heroicon-o-bolt-slash',
                'group' => 'System',
            ],
            [
                'id' => 'config-clear',
                'name' => 'Clear config cache',
                'description' => 'Drop the compiled config so the next request reads fresh env + config files.',
                'command' => 'config:clear',
                'icon' => 'heroicon-o-cog-6-tooth',
                'group' => 'System',
            ],
            [
                'id' => 'view-clear',
                'name' => 'Clear view cache',
                'description' => 'Drop compiled blade views so the next render recompiles from source.',
                'command' => 'view:clear',
                'icon' => 'heroicon-o-eye',
                'group' => 'System',
            ],
            [
                'id' => 'horizon-status',
                'name' => 'Horizon status',
                'description' => 'Report whether Horizon supervisors are running and processing jobs.',
                'command' => 'horizon:status',
                'icon' => 'heroicon-o-queue-list',
                'group' => 'Queues',
            ],
            [
                'id' => 'queue-retry',
                'name' => 'Retry all failed jobs',
                'description' => 'Push every failed_jobs row back onto the queue. Safe: does not delete failures until they succeed.',
                'command' => 'queue:retry all',
                'icon' => 'heroicon-o-arrow-uturn-left',
                'group' => 'Queues',
            ],
            [
                'id' => 'generate-config',
                'name' => 'Regenerate telephony config',
                'description' => 'Re-write every client dialplan file + the from-trunk dispatcher + the dialplan index, then reload Asterisk.',
                'command' => 'orbital:generate-config --push',
                'icon' => 'heroicon-o-phone-arrow-up-right',
                'group' => 'Telephony',
            ],
            [
                'id' => 'resync-realtime',
                'name' => 'Resync ARA tables',
                'description' => 'Rewrite all Asterisk Realtime rows from the domain models.',
                'command' => 'orbital:resync-realtime',
                'icon' => 'heroicon-o-arrows-right-left',
                'group' => 'Telephony',
            ],
            [
                'id' => 'rollup-queue-metrics',
                'name' => 'Roll up queue metrics',
                'description' => 'Aggregate yesterday\'s queue_log events into queue_metrics_daily. Normally runs nightly at 02:00.',
                'command' => 'orbital:roll-up-queue-metrics',
                'icon' => 'heroicon-o-chart-bar',
                'group' => 'Telephony',
            ],
        ];
    }

    /**
     * Run a single tool by id. Looks up the matching entry, calls
     * Artisan::call() with output capture, and stashes the result
     * on $lastOutput so the blade can render it inline.
     */
    public function runTool(string $id): void
    {
        $tool = collect($this->getTools())->firstWhere('id', $id);
        if (! $tool) {
            Notification::make()->title('Unknown tool')->body($id)->danger()->send();
            return;
        }

        // Split the command into name + args so Artisan::call handles
        // flags correctly. `orbital:generate-config --push` becomes
        // ['orbital:generate-config', ['--push' => true]].
        [$command, $args] = $this->parseCommand($tool['command']);

        try {
            $exitCode = Artisan::call($command, $args);
            $output = Artisan::output();

            $this->lastOutput[$id] = [
                'exit_code' => $exitCode,
                'output' => trim($output) === '' ? '(no output)' : $output,
                'ran_at' => now()->format('g:i:s A'),
            ];

            if ($exitCode === 0) {
                Notification::make()
                    ->title($tool['name'].' completed')
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title($tool['name'].' exited with code '.$exitCode)
                    ->warning()
                    ->send();
            }

            // Tell the matching card's Alpine component to pop its
            // output modal. Each card listens for its own id so a
            // run on one tool doesn't open modals across the grid.
            $this->dispatch('tool-ran-'.$id);

            // A handful of tools move the system-health needle
            // directly (the scheduler heartbeat, horizon status,
            // cache resets). Invalidate the cached probe snapshot
            // and nudge the top-of-page status bar so its color
            // picks up the new state immediately instead of
            // waiting on its 60s poll.
            if (in_array($id, ['schedule-run', 'horizon-status', 'cache-clear', 'config-clear'], true)) {
                \Illuminate\Support\Facades\Cache::forget('system_health:checks');
                $this->dispatch('system-health-updated');
            }
        } catch (\Throwable $e) {
            $this->lastOutput[$id] = [
                'exit_code' => -1,
                'output' => $e->getMessage(),
                'ran_at' => now()->format('g:i:s A'),
            ];
            Notification::make()
                ->title($tool['name'].' failed')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->dispatch('tool-ran-'.$id);
        }
    }

    /**
     * Split a CLI-style command into (name, args) for Artisan::call.
     * Supports `--flag` bools and `--opt=value` pairs. Positional
     * arguments like `queue:retry all` land under the numeric 0/1/…
     * keys which Artisan accepts.
     *
     * @return array{0: string, 1: array<string|int, string|bool>}
     */
    private function parseCommand(string $raw): array
    {
        $parts = preg_split('/\s+/', trim($raw)) ?: [];
        $name = array_shift($parts) ?? '';
        $args = [];
        $pos = 0;
        foreach ($parts as $part) {
            if (str_starts_with($part, '--')) {
                if (str_contains($part, '=')) {
                    [$k, $v] = explode('=', $part, 2);
                    $args[$k] = $v;
                } else {
                    $args[$part] = true;
                }
            } else {
                $args[$pos++] = $part;
            }
        }

        return [$name, $args];
    }
}
