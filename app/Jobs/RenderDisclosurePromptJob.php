<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Telephony\DisclosureRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Renders a single recording-disclosure message to the shared
 * /var/spool/asterisk/prompts/disclosures volume so Asterisk can
 * play it back at the start of a recorded call.
 *
 * Idempotent: no-op if the file already exists. Safe to dispatch on
 * every client save — DisclosureRenderer short-circuits by content
 * hash. Dispatched from the client create/edit pages and from the
 * `orbital:render-disclosures` artisan command.
 */
class RenderDisclosurePromptJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 60;

    public function __construct(
        public readonly string $message,
    ) {}

    public function handle(DisclosureRenderer $renderer): void
    {
        $renderer->ensureRendered($this->message);
    }
}
