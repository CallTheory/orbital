<?php

declare(strict_types=1);

namespace App\Services\Bootstrap\Bootstrappers;

use App\Services\Bootstrap\Bootstrapper;
use App\Services\Bootstrap\BootstrapReport;
use App\Services\Bootstrap\BootstrapStatus;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Regenerates the Asterisk config files from the current database
 * state and reloads the PBX. Uses the existing orbital:generate-config
 * artisan command under the hood, so everything the command has
 * historically done (dialplan, pjsip.conf, manager.conf, etc.) flows
 * through this bootstrapper too.
 *
 * Status check is light: we verify Asterisk is reachable on its AMI
 * port. Install runs the config generator + reload.
 */
class AsteriskBootstrapper implements Bootstrapper
{
    public function key(): string
    {
        return 'asterisk';
    }

    public function name(): string
    {
        return 'Asterisk telephony';
    }

    public function description(): string
    {
        return 'Generates the pjsip/dialplan/manager configs from the database and reloads the PBX.';
    }

    public function icon(): string
    {
        return 'heroicon-o-phone-arrow-up-right';
    }

    public function isOptional(): bool
    {
        return false;
    }

    public function status(): BootstrapReport
    {
        $host = (string) (config('telephony.asterisk.ami.host') ?: 'asterisk');
        $port = (int) (config('telephony.asterisk.ami.port') ?: 5038);
        $reachable = $this->tcpReachable($host, $port);

        return new BootstrapReport(
            status: $reachable ? BootstrapStatus::Installed : BootstrapStatus::Missing,
            message: $reachable
                ? "AMI reachable at {$host}:{$port}. Re-run install to re-publish configs."
                : "Cannot reach Asterisk AMI at {$host}:{$port}.",
            steps: [
                ['label' => "AMI at {$host}:{$port}", 'ok' => $reachable, 'detail' => null],
            ],
        );
    }

    public function install(): BootstrapReport
    {
        $host = (string) (config('telephony.asterisk.ami.host') ?: 'asterisk');
        $port = (int) (config('telephony.asterisk.ami.port') ?: 5038);

        if (! $this->tcpReachable($host, $port)) {
            return new BootstrapReport(
                status: BootstrapStatus::Missing,
                message: "Asterisk AMI at {$host}:{$port} is unreachable. Start the asterisk container first.",
            );
        }

        $generateOk = true;
        $generateDetail = null;
        try {
            Artisan::call('orbital:generate-config');
            $generateDetail = trim(Artisan::output()) ?: 'Config generated.';
        } catch (Throwable $e) {
            $generateOk = false;
            $generateDetail = $e->getMessage();
        }

        return new BootstrapReport(
            status: $generateOk ? BootstrapStatus::Installed : BootstrapStatus::Error,
            message: $generateOk ? 'Asterisk configs regenerated.' : 'Config generation failed.',
            steps: [
                ['label' => 'orbital:generate-config', 'ok' => $generateOk, 'detail' => $generateDetail],
            ],
        );
    }

    protected function tcpReachable(string $host, int $port): bool
    {
        $errno = 0;
        $errstr = '';
        $sock = @fsockopen($host, $port, $errno, $errstr, 1.5);
        if ($sock === false) {
            return false;
        }
        fclose($sock);

        return true;
    }
}
