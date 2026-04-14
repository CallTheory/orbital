<?php

declare(strict_types=1);

namespace App\Services\Health;

/**
 * One row in the system status dashboard.
 */
final class HealthCheck
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const DOWN = 'down';

    /**
     * @param  array<string, string>  $metrics
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $category,
        public readonly string $status,
        public readonly string $message,
        public readonly array $metrics = [],
        public readonly string $icon = 'heroicon-o-server',
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
        return match ($this->status) {
            self::OK => 'Operational',
            self::WARN => 'Degraded',
            self::DOWN => 'Down',
            default => 'Unknown',
        };
    }
}
