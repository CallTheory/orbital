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
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Operator workspace — softphone, intake flow viewer, call queue status.
 *
 * Same Filament shell as the admin panel (brand, logo, user menu dropdown,
 * theme) but with a different palette, its own nav, and a persistent
 * softphone component injected via render hook so it follows the
 * operator across every page in the panel.
 *
 * Access: any user with a team-less platform role (super_admin,
 * operator, supervisor, or anything else the platform operator has
 * defined). Tenant users are redirected away by PanelRedirect.
 */
class OperatorPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('operator')
            ->path('operator')
            ->profile(isSimple: false)
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
                'primary' => Color::Emerald,
                'danger' => Color::Red,
                'warning' => Color::Amber,
                'success' => Color::Emerald,
            ])
            ->navigationGroups([
                'Workspace',
                'Activity',
            ])
            ->discoverPages(in: app_path('Filament/Operator/Pages'), for: 'App\\Filament\\Operator\\Pages')
            ->pages([])
            ->userMenuItems([
                MenuItem::make()
                    ->label('Admin Panel')
                    ->url(fn () => url('/admin'))
                    ->icon('heroicon-o-cog-6-tooth')
                    ->visible(fn () => auth()->user()?->isSuperAdmin() ?? false),
                MenuItem::make()
                    ->label('Customer Portal')
                    ->url(fn () => url('/portal'))
                    ->icon('heroicon-o-globe-alt')
                    // Only visible when the current user is attached to
                    // at least one real tenant team (dog-fooding). See
                    // AdminPanelProvider for the same gating.
                    ->visible(fn () => auth()->user()?->belongsToAnyTenant() ?? false),
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
                PanelsRenderHook::HEAD_END,
                fn (): string => view('filament.partials.panel-styles')->render(),
            )
            // Persistent softphone on every operator page — same pattern
            // the old desktop.blade used, now injected via a render hook
            // so it's available on every panel page without duplicating
            // the layout.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => Blade::render('@livewire(\'softphone\')'),
            );
    }
}
