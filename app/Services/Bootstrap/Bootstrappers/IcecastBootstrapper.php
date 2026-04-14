<?php

declare(strict_types=1);

namespace App\Services\Bootstrap\Bootstrappers;

use App\Models\HoldMusicClass;
use App\Services\Bootstrap\Bootstrapper;
use App\Services\Bootstrap\BootstrapReport;
use App\Services\Bootstrap\BootstrapStatus;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verifies Icecast is up and reachable, and counts how many hold-music
 * classes are configured on the platform. Optional — the platform
 * works fine without Icecast if tenants don't use streaming hold music.
 *
 * Does NOT push mountpoint configs to Icecast — Icecast reads its own
 * xml config file at container start and we mount that via compose.
 * This bootstrapper's job is to give operators a clear "is it up?"
 * readout on the System Setup page.
 */
class IcecastBootstrapper implements Bootstrapper
{
    public function key(): string
    {
        return 'icecast';
    }

    public function name(): string
    {
        return 'Icecast hold music';
    }

    public function description(): string
    {
        return 'Optional. Streaming hold-music server. Verifies reachability and configured mountpoints.';
    }

    public function icon(): string
    {
        return 'heroicon-o-musical-note';
    }

    public function isOptional(): bool
    {
        return true;
    }

    public function status(): BootstrapReport
    {
        $host = (string) env('ICECAST_HOST', 'icecast');
        $port = (int) env('ICECAST_PORT', 8000);
        $url = "http://{$host}:{$port}/status-json.xsl";

        $reachable = false;
        try {
            $response = Http::timeout(2)->get($url);
            $reachable = $response->successful();
        } catch (Throwable) {
            $reachable = false;
        }

        $holdMusicCount = 0;
        try {
            $holdMusicCount = HoldMusicClass::count();
        } catch (Throwable) {
            $holdMusicCount = 0;
        }

        if (! $reachable) {
            return new BootstrapReport(
                status: BootstrapStatus::Missing,
                message: "Icecast is not reachable at {$host}:{$port}. Optional service.",
            );
        }

        return new BootstrapReport(
            status: BootstrapStatus::Installed,
            message: "Icecast up at {$host}:{$port}. {$holdMusicCount} hold-music classes in database.",
            steps: [
                ['label' => "Reachable at {$host}:{$port}", 'ok' => true, 'detail' => null],
                ['label' => 'Hold-music classes configured', 'ok' => $holdMusicCount > 0, 'detail' => (string) $holdMusicCount],
            ],
        );
    }

    public function install(): BootstrapReport
    {
        // Nothing to install — Icecast's config is baked into its
        // container via docker-compose volume mount. This bootstrapper
        // is read-only; its install() is an alias for status().
        return $this->status();
    }
}
