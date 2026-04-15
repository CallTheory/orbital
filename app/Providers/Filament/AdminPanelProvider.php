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
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // No ->login() — the panel uses the app-level /login form
            // (Fortify route) so there's exactly one login URL for the
            // whole platform. The login view itself is Filament-styled
            // but lives at /login (resources/views/auth/login.blade.php)
            // so one URL works for every panel.
            // Profile page (Filament's built-in EditProfile) so every
            // user has a styled self-service profile at /{panel}/profile.
            // Replaces the old Jetstream /user/profile page, which we
            // redirect into here from routes/web.php.
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
                'primary' => Color::Indigo,
                'danger' => Color::Red,
                'warning' => Color::Amber,
                'success' => Color::Emerald,
            ])
            // Offline-first avatar provider — returns a self-contained
            // SVG data URL from LocalAvatarGenerator instead of hitting
            // ui-avatars.com. Same shape across all three panels so
            // the fallback avatar is consistent everywhere.
            ->defaultAvatarProvider(\App\Filament\AvatarProviders\LocalAvatarProvider::class)
            ->navigationGroups([
                'Dashboards',
                'Platform',
                'Features',
                'Telephony',
                'Conversational AI',
                'Monitor',
                'Utilities',
                'Administration',
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([])
            ->widgets([])
            ->userMenuItems([
                // User menu layout (all three panels share this shape):
                //
                //   Profile              (top, sort -1, Filament builtin)
                //   Security             (top, sort -10)
                //   ─── theme switcher ───
                //   Admin Panel          (mid, sort 10)
                //   Operator Workspace   (mid, sort 11)
                //   Customer Portal      (mid, sort 12)
                //   ─── separator ───
                //   Sign out             (bottom, sort PHP_INT_MAX, Filament builtin)
                //
                // The profile/security section uses negative sorts
                // so Filament's view puts them in the top dropdown
                // list. Panel switches use small positive sorts so
                // they end up in the middle. The separator above
                // Sign out comes from our custom user-menu view
                // override at resources/views/vendor/filament-panels
                // /components/user-menu.blade.php, which splits the
                // after-theme-switcher section into two lists —
                // one for regular items, one for logout alone.

                MenuItem::make()
                    ->label('Security')
                    ->url(fn () => route('filament.admin.pages.security'))
                    ->icon('heroicon-o-shield-check')
                    ->sort(-10),

                MenuItem::make()
                    ->label('Operator Workspace')
                    ->url(fn () => url('/operator'))
                    ->icon('heroicon-o-device-phone-mobile')
                    ->sort(11)
                    ->visible(fn () => auth()->user()?->hasAnyPlatformRole() ?? false),
                MenuItem::make()
                    ->label('Customer Portal')
                    ->url(fn () => url('/portal'))
                    ->icon('heroicon-o-globe-alt')
                    ->sort(12)
                    // Only visible when the current user is attached to
                    // at least one real tenant team (dog-fooding). Super
                    // admin alone isn't enough — the portal is a tenant
                    // surface and a super-admin with no tenant membership
                    // has nothing meaningful to see there.
                    ->visible(fn () => auth()->user()?->belongsToAnyTenant() ?? false),
            ])
            ->navigationItems([
                // Utilities group — external dashboards that open in
                // their own tab. Separated from Monitor so the in-app
                // monitor pages (Call Logs, Unrouted Mail) don't get
                // mixed in with things that aren't Filament surfaces.
                NavigationItem::make('Logs & Metrics')
                    ->url(fn () => 'http://'.request()->getHost().':3000', shouldOpenInNewTab: true)
                    ->icon('heroicon-o-presentation-chart-line')
                    ->group('Utilities')
                    ->sort(10)
                    ->visible(fn () => self::userCanAccessTool('tooling.grafana')),
                NavigationItem::make('User Activity')
                    ->url(fn () => url('/pulse'), shouldOpenInNewTab: true)
                    ->icon('heroicon-o-chart-bar-square')
                    ->group('Utilities')
                    ->sort(11)
                    ->visible(fn () => self::userCanAccessTool('tooling.pulse')),
                NavigationItem::make('Queue Workers')
                    ->url(fn () => url('/horizon'), shouldOpenInNewTab: true)
                    ->icon('heroicon-o-queue-list')
                    ->group('Utilities')
                    ->sort(12)
                    ->visible(fn () => self::userCanAccessTool('tooling.horizon')),
                NavigationItem::make('Application Debug')
                    ->url(fn () => url('/telescope'), shouldOpenInNewTab: true)
                    ->icon('heroicon-o-magnifying-glass')
                    ->group('Utilities')
                    ->sort(13)
                    ->visible(fn () => self::userCanAccessTool('tooling.telescope')),
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
            ])
            ->authMiddleware([
                // PanelRedirect must run BEFORE Filament's Authenticate.
                // Authenticate calls canAccessPanel() and abort(403)s
                // if the user doesn't belong here; we'd rather bounce
                // them to their actual home surface. PanelRedirect lets
                // unauthed requests through to Authenticate (which
                // redirects them to /login), so there's no conflict.
                PanelRedirect::class,
                Authenticate::class,
            ])
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => view('filament.partials.panel-styles')->render(),
            )
            ->renderHook(
                PanelsRenderHook::BODY_START,
                // Status bar is only meaningful for logged-in
                // platform staff (super_admin / operator /
                // supervisor). The login page renders through this
                // same panel context (see layouts.filament-simple),
                // so without an auth check the bar leaks onto
                // /login. Tenant portal users hitting an admin
                // route would get bounced by PanelRedirect anyway,
                // but we don't want a colored bar flashing during
                // the redirect either — `hasAnyPlatformRole()`
                // returns false for tenant-only users.
                fn (): string => auth()->user()?->hasAnyPlatformRole()
                    ? \Illuminate\Support\Facades\Blade::render('@livewire(\App\Livewire\SystemStatusBar::class)')
                    : '',
            );
    }

    /**
     * Visibility helper for the Monitor → Horizon/Telescope/Pulse nav items.
     * super_admin always sees them; everyone else needs the matching
     * `tooling.*` permission.
     */
    private static function userCanAccessTool(string $permission): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }
        return method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo($permission);
    }

}
