<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Telephony\AsteriskConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RegenerateTelephonyConfig implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 10;

    public function __construct(
        public ?int $teamId = null,
    ) {}

    public function handle(AsteriskConfigService $service): void
    {
        Log::info('Regenerating telephony config', ['team_id' => $this->teamId]);

        $service->writeConfigs($this->teamId);

        if ($service->reloadAsterisk()) {
            Log::info('Asterisk reloaded successfully');
        } else {
            Log::warning('Asterisk reload failed — config written but not applied');
        }
    }

    public function uniqueId(): string
    {
        return 'regen-telephony-'.($this->teamId ?? 'all');
    }
}
