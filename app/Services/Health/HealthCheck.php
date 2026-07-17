<?php

declare(strict_types=1);

namespace App\Services\Health;

/**
 * One row in the system status dashboard.
 *
 * Raw `status` always reflects the true state of the
 * underlying component — the per-card display never lies
 * about what's actually happening. If an operator has
 * "acknowledged" the check (one-click mute from the card),
 * the acknowledgment metadata is attached via `$ack` and the
 * card renders an extra "Acknowledged by X at Y" line; the
 * aggregate rollup (`SystemHealthService::summarize()`) reads
 * `effectiveStatus()` which returns OK for acked cards so the
 * top-of-page status bar, sidebar nav badge, and summary all
 * stay green during planned maintenance.
 */
final class HealthCheck
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const DOWN = 'down';

    /**
     * Component isn't present in this deployment topology (e.g. a
     * docker-compose-only service that the k8s chart doesn't run).
     * Rolls up as a non-problem everywhere: effectiveStatus() treats
     * it like OK, isOk() still reports false (it isn't literally
     * "up"), and the card renders gray rather than red/yellow so
     * operators aren't paged for infrastructure that was never
     * installed.
     */
    public const NOT_DEPLOYED = 'not_deployed';

    /**
     * @param  array<string, string>  $metrics
     * @param  array{user_name: string, acknowledged_at: string, reason: ?string, id: int}|null  $ack
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $category,
        public readonly string $status,
        public readonly string $message,
        public readonly array $metrics = [],
        public readonly string $icon = 'heroicon-o-server',
        public readonly ?array $ack = null,
    ) {}

    /**
     * Build a check for a component that isn't part of this
     * deployment's topology at all (as opposed to one that's
     * deployed but unreachable). Used by
     * SystemHealthService::applyDisabled() to replace the raw probe
     * result for components listed in config('health.disabled').
     */
    public static function notDeployed(string $key, string $name, string $category, string $icon): self
    {
        return new self(
            key: $key,
            name: $name,
            category: $category,
            status: self::NOT_DEPLOYED,
            message: 'Not part of this deployment',
            icon: $icon,
        );
    }

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }

    public function isWarn(): bool
    {
        return $this->status === self::WARN;
    }

    public function isDown(): bool
    {
        return $this->status === self::DOWN;
    }

    public function isAcknowledged(): bool
    {
        return $this->ack !== null;
    }

    /**
     * Status used for aggregate rollup. Acknowledged cards
     * count as OK for the nav badge / status bar / summary
     * so planned maintenance doesn't trip alarms, but the
     * card itself still renders the raw state so the
     * operator knows the component is actually down.
     *
     * NOT_DEPLOYED rolls up as OK too — it's never a problem to
     * flag, just a component this topology doesn't run.
     */
    public function effectiveStatus(): string
    {
        if ($this->isAcknowledged() || $this->status === self::NOT_DEPLOYED) {
            return self::OK;
        }

        return $this->status;
    }

    public function color(): string
    {
        return match ($this->status) {
            self::OK => 'success',
            self::WARN => 'warning',
            self::DOWN => 'danger',
            self::NOT_DEPLOYED => 'gray',
            default => 'gray',
        };
    }

    public function statusLabel(): string
    {
        // Keep these labels in lockstep with the nav badge
        // (Dashboard::getNavigationBadge), the status bar
        // dispatch (SystemStatusBar::load), and the roll-up
        // summary in SystemHealthService::summarize. One
        // vocabulary across every surface so operators see the
        // same word everywhere.
        return match ($this->status) {
            self::OK => 'OK',
            self::WARN => 'Degraded',
            self::DOWN => 'Problem',
            self::NOT_DEPLOYED => 'Not Deployed',
            default => 'Unknown',
        };
    }

    /**
     * Return a new HealthCheck identical to this one but with
     * the given ack metadata attached. Used by the service to
     * layer active acks onto raw check results.
     *
     * @param  array{user_name: string, acknowledged_at: string, reason: ?string, id: int}  $ack
     */
    public function withAck(array $ack): self
    {
        return new self(
            key: $this->key,
            name: $this->name,
            category: $this->category,
            status: $this->status,
            message: $this->message,
            metrics: $this->metrics,
            icon: $this->icon,
            ack: $ack,
        );
    }
}
