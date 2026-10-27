<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CallSessionState;
use App\Services\Flows\PostCallActions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs a finished call's email / web-call steps off the request that
 * ended it. One try: a retry could send the email twice, and each
 * action's outcome is recorded on the session either way.
 */
class RunPostCallActionsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public int $sessionId,
    ) {}

    public function handle(PostCallActions $actions): void
    {
        $state = CallSessionState::find($this->sessionId);
        if ($state) {
            $actions->run($state);
        }
    }
}
