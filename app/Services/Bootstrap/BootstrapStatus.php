<?php

declare(strict_types=1);

namespace App\Services\Bootstrap;

/**
 * High-level readiness state for an external service.
 *
 *   installed — all sub-steps passed, service is ready to use
 *   partial   — service is reachable but some configuration is missing
 *               (e.g. MinIO is up but the recordings bucket isn't there)
 *   missing   — service is configured but unreachable (container not
 *               running, optional service disabled, etc)
 *   error     — install attempted and failed — manual intervention needed
 */
enum BootstrapStatus: string
{
    case Installed = 'installed';
    case Partial = 'partial';
    case Missing = 'missing';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Installed => 'Installed',
            self::Partial => 'Partially installed',
            self::Missing => 'Not installed',
            self::Error => 'Error',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Installed => 'success',
            self::Partial => 'warning',
            self::Missing => 'gray',
            self::Error => 'danger',
        };
    }
}
