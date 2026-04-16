<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

/**
 * Reads and manages the platform's TLS certificate.
 *
 * The cert lives on the shared `tls-certs` Docker volume at the
 * path configured in `config('tls.cert_path')`. acme.sh writes it
 * there after DNS-01 validation against Let's Encrypt; this service
 * only READS the PEM file, never writes to it. The Filament dashboard
 * and SystemHealthService both consume this for monitoring.
 *
 * `issue()` and `renew()` shell out to `docker compose exec acme ...`
 * to trigger acme.sh inside the acme container. These are admin-only
 * manual actions — auto-renewal is handled by acme.sh's built-in
 * daemon cron and the deploy-hook webhook.
 *
 * In Kubernetes, cert-manager replaces acme.sh but writes the same
 * PEM files to the same mount path. This class is environment-agnostic.
 */
class CertificateService
{
    public function certExists(): bool
    {
        $path = (string) config('tls.cert_path');
        return $path !== '' && is_file($path) && filesize($path) > 0;
    }

    public function getCertificateInfo(): ?CertInfo
    {
        if (! $this->certExists()) {
            return null;
        }

        $pem = file_get_contents((string) config('tls.cert_path'));
        if ($pem === false) {
            return null;
        }

        $cert = openssl_x509_parse($pem);
        if ($cert === false) {
            return null;
        }

        $validFrom = (new DateTimeImmutable)->setTimestamp($cert['validFrom_time_t'] ?? 0);
        $validTo = (new DateTimeImmutable)->setTimestamp($cert['validTo_time_t'] ?? 0);
        $now = new DateTimeImmutable;
        $daysRemaining = (int) $now->diff($validTo)->format('%r%a');

        $issuer = $this->formatIssuer($cert['issuer'] ?? []);
        $serial = strtoupper($cert['serialNumberHex'] ?? dechex($cert['serialNumber'] ?? 0));
        $sanList = $this->parseSanList($cert['extensions']['subjectAltName'] ?? '');
        $domain = $cert['subject']['CN'] ?? '';
        $isWildcard = str_starts_with($domain, '*.');
        $isStaging = str_contains($issuer, 'STAGING') || str_contains($issuer, 'Fake');

        return new CertInfo(
            domain: $domain,
            issuer: $issuer,
            validFrom: $validFrom,
            validTo: $validTo,
            daysRemaining: $daysRemaining,
            serial: $serial,
            sanList: $sanList,
            isWildcard: $isWildcard,
            isStaging: $isStaging,
        );
    }

    public function isExpired(): bool
    {
        $info = $this->getCertificateInfo();
        return $info !== null && $info->isExpired();
    }

    public function isExpiringSoon(int $thresholdDays = 14): bool
    {
        $info = $this->getCertificateInfo();
        return $info !== null && $info->isExpiringSoon($thresholdDays);
    }

    /**
     * Trigger acme.sh to issue a new certificate. Runs synchronously
     * and returns the command output. Called from the Filament
     * dashboard's "Issue Certificate" action.
     *
     * Uses --staging by default unless `$production` is true, to
     * avoid burning Let's Encrypt rate limits during testing.
     */
    public function issue(bool $production = false): string
    {
        $domain = (string) config('tls.domain');
        $provider = (string) config('tls.dns_provider');

        if ($domain === '' || $provider === '') {
            return 'Error: ACME_DOMAIN and ACME_DNS_PROVIDER must be set in .env';
        }

        $staging = $production ? '' : '--staging';
        $cmd = implode(' ', array_filter([
            'docker compose exec -T acme acme.sh --issue',
            "-d {$domain}",
            "-d \"*.{$domain}\"",
            "--dns {$provider}",
            $staging,
            '--install-cert',
            '--fullchain-file /etc/acme-certs/fullchain.pem',
            '--key-file /etc/acme-certs/privkey.pem',
            '--ca-file /etc/acme-certs/ca.pem',
            '--reloadcmd "/opt/deploy-hook.sh"',
        ]));

        $output = [];
        $exitCode = 0;
        exec($cmd . ' 2>&1', $output, $exitCode);

        return implode("\n", $output) . "\n[exit code: {$exitCode}]";
    }

    /**
     * Force-renew the existing certificate. Useful when the cert
     * is valid but the admin wants to rotate early (e.g. after a
     * key compromise or a staging→production switch).
     */
    public function renew(bool $production = false): string
    {
        $domain = (string) config('tls.domain');
        if ($domain === '') {
            return 'Error: ACME_DOMAIN must be set in .env';
        }

        $staging = $production ? '' : '--staging';
        $cmd = implode(' ', array_filter([
            'docker compose exec -T acme acme.sh --renew',
            "-d {$domain}",
            '--force',
            $staging,
        ]));

        $output = [];
        $exitCode = 0;
        exec($cmd . ' 2>&1', $output, $exitCode);

        return implode("\n", $output) . "\n[exit code: {$exitCode}]";
    }

    /**
     * Static list of services that mount the tls-certs volume.
     * Used by the dashboard to show which services consume the cert.
     *
     * @return array<int, array{name: string, port: string, protocol: string}>
     */
    public function getConsumingServices(): array
    {
        return [
            ['name' => 'nginx-tls', 'port' => '443', 'protocol' => 'HTTPS + WSS'],
            ['name' => 'Asterisk', 'port' => '5061 / 8089', 'protocol' => 'SIP TLS + WSS'],
            ['name' => 'Kamailio', 'port' => '5061', 'protocol' => 'SIP TLS'],
            ['name' => 'Haraka', 'port' => '25', 'protocol' => 'SMTP STARTTLS'],
            ['name' => 'LiveKit', 'port' => '7880', 'protocol' => 'HTTPS + WSS'],
        ];
    }

    private function formatIssuer(array $issuer): string
    {
        $org = $issuer['O'] ?? '';
        $cn = $issuer['CN'] ?? '';
        if ($org !== '' && $cn !== '') {
            return "{$org} ({$cn})";
        }
        return $org ?: $cn ?: 'Unknown';
    }

    /**
     * @return list<string>
     */
    private function parseSanList(string $sanString): array
    {
        if ($sanString === '') {
            return [];
        }
        $sans = [];
        foreach (explode(',', $sanString) as $san) {
            $san = trim($san);
            if (str_starts_with($san, 'DNS:')) {
                $sans[] = substr($san, 4);
            }
        }
        return $sans;
    }
}
