<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Http\Middleware\PanelRedirect;
use App\Http\Middleware\SetPermissionsTeamContext;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Customer portal — the read-only view tenant users land on when they
 * log in. Same Filament shell as admin and operator, scoped to the
 * tenant user's `current_team_id`.
 *
 * Super-admins can also access the portal via impersonation so they
 * can see what a customer sees.
 */
class PortalPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('portal')
            ->path('portal')
            ->profile(page: \App\Filament\Auth\EditProfile::class, isSimple: false)
            ->brandName('Orbital')
            ->brandLogo(fn () => new HtmlString(
                '<div class="orbital-brand">'
                    .'<img src="'.asset('images/orbital-logo.png').'" alt="Orbital" class="orbital-brand-img">'
                    .'<span class="orbital-brand-text">Orbital</span>'
                .'</div>'
            ))
            ->brandLogoHeight('1.75rem')
            ->favicon(fn () => asset('images/orbital-logo.png'))
            ->colors([
                'primary' => Color::Sky,
                'danger' => Color::Red,
                'warning' => Color::Amber,
                'success' => Color::Emerald,
            ])
            // Offline-first avatar provider — see AdminPanelProvider.
            ->defaultAvatarProvider(\App\Filament\AvatarProviders\LocalAvatarProvider::class)
            ->navigationGroups([
                'Activity',
            ])
            ->discoverPages(in: app_path('Filament/Portal/Pages'), for: 'App\\Filament\\Portal\\Pages')
            ->pages([
                // Shared security page — 2FA, password, sessions.
                \App\Filament\Pages\Security::class,
            ])
            ->userMenuItems([
                // Layout mirrors AdminPanelProvider — see that file
                // for the full section layout explanation.
                MenuItem::make()
                    ->label('Security')
                    ->url(fn () => route('filament.portal.pages.security'))
                    ->icon('heroicon-o-shield-check')
                    ->sort(-10),
                MenuItem::make()
                    ->label('Admin Panel')
                    ->url(fn () => url('/admin'))
                    ->icon('heroicon-o-cog-6-tooth')
                    ->sort(10)
                    ->visible(fn () => auth()->user()?->isSuperAdmin() ?? false),
                MenuItem::make()
                    ->label('Operator Workspace')
                    ->url(fn () => url('/operator'))
                    ->icon('heroicon-o-device-phone-mobile')
                    ->sort(11)
                    ->visible(fn () => auth()->user()?->hasAnyPlatformRole() ?? false),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SetPermissionsTeamContext::class,
                // See AdminPanelProvider for why PanelRedirect lives in
                // general middleware rather than authMiddleware.
                PanelRedirect::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->renderHook(
                \Filament\View\PanelsRenderHook::HEAD_END,
                fn (): string => view('filament.partials.panel-styles')->render(),
            );
    }
}
