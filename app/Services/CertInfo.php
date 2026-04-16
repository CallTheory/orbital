<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Readonly value object holding parsed TLS certificate metadata.
 *
 * Populated by CertificateService::getCertificateInfo() from a
 * PEM file on the shared tls-certs volume. The Filament dashboard
 * + health probe both consume this to decide display state
 * (green/yellow/red) and surface human-readable details.
 */
final readonly class CertInfo
{
    public function __construct(
        public string $domain,
        public string $issuer,
        public \DateTimeImmutable $validFrom,
        public \DateTimeImmutable $validTo,
        public int $daysRemaining,
        public string $serial,
        /** @var list<string> */
        public array $sanList,
        public bool $isWildcard,
        public bool $isStaging,
    ) {}

    public function isExpired(): bool
    {
        return $this->daysRemaining < 0;
    }

    public function isExpiringSoon(int $thresholdDays = 14): bool
    {
        return ! $this->isExpired() && $this->daysRemaining <= $thresholdDays;
    }

    /**
     * Color for the Filament badge on the dashboard.
     *   green  → >30 days remaining
     *   yellow → 14-30 days
     *   red    → <14 days
     *   danger → expired
     */
    public function badgeColor(): string
    {
        if ($this->isExpired()) {
            return 'danger';
        }
        if ($this->daysRemaining <= 14) {
            return 'danger';
        }
        if ($this->daysRemaining <= 30) {
            return 'warning';
        }
        return 'success';
    }
}
