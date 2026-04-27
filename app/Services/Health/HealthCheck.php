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
     */
    public function effectiveStatus(): string
    {
        return $this->isAcknowledged() ? self::OK : $this->status;
    }

    public function color(): string
    {
        return match ($this->status) {
            self::OK => 'success',
            self::WARN => 'warning',
            self::DOWN => 'danger',
            default => 'gray',
        };
    }

    public function statusLabel(): string
    {
        // Keep these three labels in lockstep with the nav badge
        // (Dashboard::getNavigationBadge), the status bar
        // dispatch (SystemStatusBar::load), and the roll-up
        // summary in SystemHealthService::summarize. One
        // vocabulary across every surface so operators see the
        // same word everywhere.
        return match ($this->status) {
            self::OK => 'OK',
            self::WARN => 'Degraded',
            self::DOWN => 'Problem',
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
