<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\CertificateService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use UnitEnum;

/**
 * TLS certificate monitoring + management dashboard.
 *
 * Shows the current wildcard cert's domain, issuer, expiry (color-
 * coded), and which services consume it. Actions for issuing a
 * new cert (triggers acme.sh via the CertificateService) and
 * force-renewing. Mostly a read-only monitoring surface — the
 * auto-renewal path is acme.sh's daemon cron + deploy-hook
 * webhook, not this page.
 *
 * Hidden from the nav when `tls.enabled` is false so installs
 * that haven't configured ACME don't see a dead page.
 */
class TlsCertificates extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-lock-closed';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Certificates';

    protected static ?string $title = 'TLS Certificates';

    protected ?string $subheading = 'Managed TLS certificates for the platform.';

    protected static ?string $slug = 'tls-certificates';

    protected string $view = 'filament.pages.tls-certificates';

    /** @var array<string, mixed>|null Serialized cert info (plain array, not CertInfo object — Livewire can't hydrate DateTimeImmutable) */
    public ?array $cert = null;

    /** @var array<int, array{name: string, port: string, protocol: string}> */
    public array $services = [];

    public bool $certExists = false;

    public static function canAccess(): bool
    {
        if (! config('tls.enabled')) {
            return false;
        }
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->refreshCertInfo();
    }

    public function refreshCertInfo(): void
    {
        $service = app(CertificateService::class);
        $this->certExists = $service->certExists();
        $this->services = $service->getConsumingServices();

        $info = $service->getCertificateInfo();
        if ($info) {
            $this->cert = [
                'domain' => $info->domain,
                'issuer' => $info->issuer,
                'validFrom' => $info->validFrom->format('M j, Y'),
                'validTo' => $info->validTo->format('M j, Y'),
                'daysRemaining' => $info->daysRemaining,
                'serial' => $info->serial,
                'sanList' => $info->sanList,
                'isWildcard' => $info->isWildcard,
                'isStaging' => $info->isStaging,
                'badgeColor' => $info->badgeColor(),
                'isExpired' => $info->isExpired(),
            ];
        } else {
            $this->cert = null;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('issue')
                ->label('Issue Certificate')
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Issue a new certificate')
                ->modalDescription(fn (): string => 'This will request a wildcard certificate for '
                    .(config('tls.domain') ?: '(no domain configured)')
                    .' from Let\'s Encrypt via DNS-01 validation using the '
                    .(config('tls.dns_provider') ?: '(no provider configured)')
                    .' DNS provider. Uses the staging server by default — check "Production" to issue a real cert.')
                ->schema([
                    \Filament\Forms\Components\Toggle::make('production')
                        ->label('Production (real cert)')
                        ->default(false)
                        ->helperText('Off = Let\'s Encrypt staging (for testing, browsers show warnings). On = production cert (rate-limited, trusted by browsers).'),
                ])
                ->action(function (array $data) {
                    $service = app(CertificateService::class);
                    $output = $service->issue(production: (bool) ($data['production'] ?? false));
                    Notification::make()
                        ->title('Certificate issuance triggered')
                        ->body('Check the output for success/failure. This may take 1-2 minutes for DNS propagation.')
                        ->success()
                        ->send();
                    $this->refreshCertInfo();
                }),

            Action::make('renew')
                ->label('Force Renew')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => $this->certExists)
                ->requiresConfirmation()
                ->modalHeading('Force-renew the certificate')
                ->modalDescription('This renews immediately regardless of expiry date. Useful after switching from staging to production or after a key compromise.')
                ->action(function () {
                    $service = app(CertificateService::class);
                    $output = $service->renew();
                    Notification::make()
                        ->title('Renewal triggered')
                        ->success()
                        ->send();
                    $this->refreshCertInfo();
                }),
        ];
    }
}
