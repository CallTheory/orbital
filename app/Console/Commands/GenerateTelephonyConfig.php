<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Telephony\AsteriskConfigService;
use Illuminate\Console\Command;

class GenerateTelephonyConfig extends Command
{
    protected $signature = 'orbital:generate-config
                            {--push : Also reload Asterisk after generating}
                            {--team= : Generate for a specific team ID}';

    protected $description = 'Generate Asterisk configuration files from database';

    public function handle(AsteriskConfigService $service): int
    {
        $teamId = $this->option('team') ? (int) $this->option('team') : null;

        $this->info('Generating telephony configuration...');

        if ($teamId !== null) {
            $service->writeDialplanForTenant($teamId);
        } else {
            $service->writeAllDialplans();
            $service->writeDialplanIndex();
        }

        $path = config('telephony.asterisk.config_path');
        $this->info("Configs written to: {$path}");

        if ($this->option('push')) {
            $this->info('Reloading Asterisk...');
            if ($service->reloadAsterisk()) {
                $this->info('Asterisk reloaded successfully.');
            } else {
                $this->error('Asterisk reload failed. Check AMI connection.');

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
