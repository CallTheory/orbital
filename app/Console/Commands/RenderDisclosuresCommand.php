<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\Telephony\DisclosureRenderer;
use Illuminate\Console\Command;

/**
 * Walks every disclosure message in the system — platform default +
 * every client override — and renders its TTS audio to the shared
 * prompts volume so Asterisk has prompt files to play at call time.
 *
 * Run after changing the platform default, during a fresh deploy, or
 * any time you suspect a prompt file is out of sync with the DB.
 * Individual client edits fire RenderDisclosurePromptJob automatically,
 * so this is mostly a bulk/recovery tool.
 *
 *   ./vendor/bin/sail artisan orbital:render-disclosures
 */
class RenderDisclosuresCommand extends Command
{
    protected $signature = 'orbital:render-disclosures
        {--force : Re-render even if the file already exists (deletes cached files first)}';

    protected $description = 'Render all recording-disclosure TTS prompts (platform default + every client override).';

    public function handle(DisclosureRenderer $renderer): int
    {
        $messages = $this->collectMessages();

        if (empty($messages)) {
            $this->info('No disclosure messages configured. Nothing to render.');
            return self::SUCCESS;
        }

        $this->info(sprintf('Rendering %d unique disclosure message(s)…', count($messages)));

        $rendered = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($messages as $message => $origins) {
            $path = $renderer->pathFor($message);
            if ($path === null) {
                $skipped++;
                continue;
            }

            if ($this->option('force') && is_file($path)) {
                @unlink($path);
            }

            $already = is_file($path);
            $result = $renderer->ensureRendered($message);

            if ($result === null) {
                $failed++;
                $this->warn(sprintf('  ✗ (%s) %s', implode(', ', $origins), $this->preview($message)));
                continue;
            }

            if ($already) {
                $skipped++;
                $this->line(sprintf('  ↳ cached (%s) %s', implode(', ', $origins), $this->preview($message)));
            } else {
                $rendered++;
                $this->line(sprintf('  ✓ rendered (%s) %s', implode(', ', $origins), $this->preview($message)));
            }
        }

        $this->newLine();
        $this->info("Rendered: {$rendered}  |  Cached: {$skipped}  |  Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Build a map of `message => [list of origin labels]` so duplicate
     * messages across clients only render once. The origin list is
     * cosmetic — it's what the command output uses to show which
     * clients share a given prompt.
     *
     * @return array<string, array<int, string>>
     */
    protected function collectMessages(): array
    {
        $map = [];

        $platform = (string) config('telephony.recording.disclosure_message', '');
        if ($platform !== '') {
            $map[$platform][] = 'platform';
        }

        Team::query()
            ->whereNotNull('recording_overrides')
            ->chunkById(500, function ($teams) use (&$map) {
                foreach ($teams as $team) {
                    $overrides = $team->recording_overrides ?? [];
                    $msg = (string) ($overrides['disclosure_message'] ?? '');
                    if ($msg === '') {
                        continue;
                    }
                    $map[$msg][] = "client:{$team->id}";
                }
            });

        return $map;
    }

    protected function preview(string $message): string
    {
        $flat = preg_replace('/\s+/', ' ', $message) ?? '';
        return strlen($flat) > 70 ? substr($flat, 0, 67).'…' : $flat;
    }
}
