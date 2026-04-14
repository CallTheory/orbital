<?php

declare(strict_types=1);

namespace App\Services\Bootstrap;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Singleton registry of every {@see Bootstrapper} installed in the app.
 *
 * Registration happens in App\Providers\AppServiceProvider::boot() so
 * bootstrappers are available as soon as the app is booted — from the
 * artisan command, the Filament page, and the seeder alike.
 */
class BootstrapRegistry
{
    /** @var array<string, Bootstrapper> */
    protected array $bootstrappers = [];

    public function register(Bootstrapper $bootstrapper): self
    {
        $this->bootstrappers[$bootstrapper->key()] = $bootstrapper;
        return $this;
    }

    /**
     * @return array<string, Bootstrapper>
     */
    public function all(): array
    {
        return $this->bootstrappers;
    }

    public function get(string $key): ?Bootstrapper
    {
        return $this->bootstrappers[$key] ?? null;
    }

    /**
     * Gather a status snapshot for every registered bootstrapper.
     *
     * @return array<string, BootstrapReport>
     */
    public function statusAll(): array
    {
        $out = [];
        foreach ($this->bootstrappers as $key => $b) {
            try {
                $out[$key] = $b->status();
            } catch (Throwable $e) {
                Log::warning('bootstrap status failed', ['service' => $key, 'error' => $e->getMessage()]);
                $out[$key] = new BootstrapReport(
                    status: BootstrapStatus::Error,
                    message: $e->getMessage(),
                );
            }
        }
        return $out;
    }

    /**
     * Run install() on every registered bootstrapper in registration
     * order. Optional bootstrappers that throw are logged and skipped
     * so a degraded optional service doesn't halt the whole chain.
     *
     * @return array<string, BootstrapReport>
     */
    public function runAll(): array
    {
        $out = [];
        foreach ($this->bootstrappers as $key => $b) {
            try {
                $out[$key] = $b->install();
            } catch (Throwable $e) {
                Log::warning('bootstrap install failed', ['service' => $key, 'error' => $e->getMessage()]);
                $out[$key] = new BootstrapReport(
                    status: BootstrapStatus::Error,
                    message: $e->getMessage(),
                );
                if (! $b->isOptional()) {
                    // Re-throw hard failures so the caller (seeder /
                    // console command) can decide whether to bail.
                    throw $e;
                }
            }
        }
        return $out;
    }
}
