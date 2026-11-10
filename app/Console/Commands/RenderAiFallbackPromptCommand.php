<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Telephony\AiFallbackPrompt;
use Illuminate\Console\Command;

/**
 * Render the message callers hear when the AI can't take their call (see
 * AiFallbackPrompt). Cheap when nothing changed, so the scheduler runs it
 * regularly; that also retries a render that failed.
 */
class RenderAiFallbackPromptCommand extends Command
{
    protected $signature = 'orbital:render-ai-fallback
        {--force : Re-render even if the current audio matches the settings}';

    protected $description = 'Render the "we\'re having trouble, please call back" message played when the AI can\'t take a call.';

    public function handle(AiFallbackPrompt $prompt): int
    {
        $result = $prompt->ensureRendered((bool) $this->option('force'));

        match ($result) {
            'rendered' => $this->info('Rendered the AI-unavailable message.'),
            'current' => $this->line('The AI-unavailable message is up to date.'),
            'empty' => $this->warn('No message configured.'),
            'failed' => $this->warn('Could not render it (check the TTS provider key). Callers hear Asterisk\'s stock prompts meanwhile.'),
        };

        return $result === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
