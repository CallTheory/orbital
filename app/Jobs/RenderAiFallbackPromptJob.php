<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Telephony\AiFallbackPrompt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Render the AI-unavailable message if its wording or voice changed.
 * Dispatched when Platform Settings are saved; the scheduler also runs
 * `orbital:render-ai-fallback`, which covers a render that failed (for
 * instance before a TTS key was set).
 */
class RenderAiFallbackPromptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public function handle(AiFallbackPrompt $prompt): void
    {
        $prompt->ensureRendered();
    }
}
