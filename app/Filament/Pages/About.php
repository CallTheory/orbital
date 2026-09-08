<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\Licensing\SupportSubscription;
use App\Support\Release;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use UnitEnum;

/**
 * About — what this installation is, what license it's under, and where
 * its source lives.
 *
 * This page is one half of Orbital's AGPL section 13 compliance (the panel
 * footers are the other half, because portal users never reach here). It
 * names the exact version and commit so an operator can answer "what am I
 * running" without shelling into a container, and links to the
 * corresponding source for that build.
 *
 * Deliberately NOT super-admin gated. Section 13 talks about "all users
 * interacting with it remotely through a computer network" — restricting
 * the source offer to the one account that already has shell access would
 * defeat the point. Any authenticated platform user can read this page.
 * The support-key action inside it is separately gated.
 */
class About extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-information-circle';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 99;

    protected static ?string $navigationLabel = 'About';

    protected static ?string $title = 'About Orbital';

    protected static ?string $slug = 'about';

    protected string $view = 'filament.pages.about';

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function getSubheading(): ?string
    {
        return 'Version, license, and source for this installation.';
    }

    /**
     * @return array<string, mixed>
     */
    public function getRelease(): array
    {
        return [
            'version' => Release::version(),
            'display' => Release::display(),
            'commit' => Release::commit(),
            'short_commit' => Release::shortCommit(),
            'channel' => Release::channel(),
            'license_spdx' => Release::licenseSpdx(),
            'license_name' => Release::licenseName(),
            'license_url' => Release::licenseUrl(),
            'source_url' => Release::sourceUrl(),
            'source_url_commit' => Release::sourceUrlForCommit(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSupport(): array
    {
        $subscription = app(SupportSubscription::class);

        return [
            'status' => $subscription->statusLabel(),
            'active' => $subscription->isActive(),
            'expired' => $subscription->isExpired(),
            'tier' => $subscription->tier(),
            'licensee' => $subscription->licensee(),
            'portal_url' => config('orbital.support.portal_url'),
            'configurable' => is_string(config('orbital.support.public_key'))
                && config('orbital.support.public_key') !== '',
        ];
    }

    /**
     * The runtime environment facts a support engineer asks for first.
     *
     * @return array<string, string>
     */
    public function getEnvironment(): array
    {
        return [
            'PHP' => PHP_VERSION,
            'Laravel' => app()->version(),
            'Environment' => (string) app()->environment(),
            'Database' => (string) config('database.default'),
            'Cache' => (string) config('cache.default'),
            'Queue' => (string) config('queue.default'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewSource')
                ->label('View source')
                ->icon('heroicon-o-code-bracket')
                ->color('gray')
                ->url(Release::sourceUrlForCommit())
                ->openUrlInNewTab(),

            Action::make('supportKey')
                ->label('Support subscription key')
                ->icon('heroicon-o-key')
                ->color('gray')
                ->visible(fn (): bool => (auth()->user()?->isSuperAdmin() ?? false)
                    && $this->getSupport()['configurable'])
                ->modalHeading('Support subscription key')
                ->modalDescription('Paste the key from your support agreement. This enables in-app ticket submission and the signed update channel. It does not change what Orbital does — every feature stays available with or without it. Leave the field empty to remove an existing key.')
                ->modalSubmitActionLabel('Save')
                ->schema([
                    Textarea::make('key')
                        ->label('Key')
                        ->rows(4)
                        ->default(fn (): string => '')
                        ->helperText('One long line of the form <payload>.<signature>. Verified offline — Orbital never contacts a license server.'),
                ])
                ->action(function (array $data): void {
                    $error = app(SupportSubscription::class)->store((string) ($data['key'] ?? ''));

                    if ($error !== null) {
                        Notification::make()
                            ->title('Key not accepted')
                            ->body($error)
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Support subscription updated')
                        ->success()
                        ->send();
                }),
        ];
    }
}
