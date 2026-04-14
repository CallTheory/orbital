<?php

declare(strict_types=1);

namespace App\Services\Bootstrap;

/**
 * A Bootstrapper is a named, idempotent initializer for an external
 * service dependency. Think "make sure MinIO has its buckets" or
 * "publish the Asterisk dialplan and reload AMI".
 *
 * Implementations are registered in {@see BootstrapRegistry} and
 * surfaced through both an artisan command (`orbital:bootstrap`) and
 * the admin panel's System Setup page, so the platform can be
 * bootstrapped from the terminal or the web UI interchangeably.
 *
 * Every method MUST be idempotent — running `status()` or `install()`
 * twice in a row should be safe and return the same result.
 */
interface Bootstrapper
{
    /**
     * Stable machine-readable identifier. Used for selecting a specific
     * bootstrapper from the CLI and as the key in registry lookups.
     */
    public function key(): string;

    /**
     * Human-readable display name shown on the SystemSetup page.
     */
    public function name(): string;

    /**
     * One-line description of what this bootstrapper actually does.
     */
    public function description(): string;

    /**
     * Heroicon name for the service card.
     */
    public function icon(): string;

    /**
     * Whether this bootstrapper's service is optional. Optional
     * services (e.g. Ollama, Icecast) that are unreachable get
     * reported as "missing" rather than "error", and the seeder
     * skips over install failures instead of halting.
     */
    public function isOptional(): bool;

    /**
     * Inspect the external service and report its current readiness
     * state without making any changes.
     */
    public function status(): BootstrapReport;

    /**
     * Run the install steps idempotently. Returns a report describing
     * what was done (or couldn't be done).
     */
    public function install(): BootstrapReport;
}
