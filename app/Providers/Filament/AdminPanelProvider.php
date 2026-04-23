<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Auth\EditProfile;
use App\Filament\AvatarProviders\LocalAvatarProvider;
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
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
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
            ->profile(page: EditProfile::class, isSimple: false)
            ->brandName(fn () => (string) config('orbital.platform_name', 'Orbital'))
            // Filament renders BOTH logos in the chrome and CSS swaps
            // between them based on the active color mode. The 512×128
            // uploaded logo already contains the wordmark, so no
            // separate text span here.
            ->brandLogo(fn () => \App\Support\Branding::platformLogoLightUrl())
            ->darkModeBrandLogo(fn () => \App\Support\Branding::platformLogoDarkUrl())
            ->brandLogoHeight('2rem')
            ->favicon(fn () => \App\Support\Branding::platformFaviconUrl())
            ->colors([
                'primary' => \App\Support\Branding::adminPrimaryColor(),
                'danger' => Color::Red,
                'warning' => Color::Amber,
                'success' => Color::Emerald,
            ])
            // Offline-first avatar provider — returns a self-contained
            // SVG data URL from LocalAvatarGenerator instead of hitting
            // ui-avatars.com. Same shape across all three panels so
            // the fallback avatar is consistent everywhere.
            ->defaultAvatarProvider(LocalAvatarProvider::class)
            // Filament database notifications + polling — the
            // canonical way to get a persistent toast when
            // something (like AsteriskDrainService) sends a
            // notification to the current user. The bell icon
            // in the topbar shows unread count; toasts pop
            // automatically on new arrivals within the poll
            // interval. 5s is tight enough that operators feel
            // the drain heads-up as near-real-time without
            // hammering the DB.
            ->databaseNotifications()
            ->databaseNotificationsPolling('5s')
            ->navigationGroups([
                'Monitor',
                'Customers',
                'Platform',
                'Workflow',
                'Preferences',
                'Conversational AI',
                'Telephony',
                'System',
                'Platform Utilities',
                'Control Panels',
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
                    // at least one real client team (dog-fooding). Super
                    // admin alone isn't enough — the portal is a client
                    // surface and a super-admin with no client membership
                    // has nothing meaningful to see there.
                    ->visible(fn () => auth()->user()?->belongsToAnyTenant() ?? false),
            ])
            ->navigationItems([
                // Platform Utilities group — in-house Laravel tooling
                // that ships with the app (Pulse, Horizon, Telescope).
                // These live under our own URL paths and are gated by
                // `tooling.*` permissions so operators can see them
                // without being full super-admins.
                NavigationItem::make('Laravel Pulse')
                    ->url(fn () => url('/pulse'), shouldOpenInNewTab: true)
                    ->icon('heroicon-o-cursor-arrow-ripple')
                    ->group('Platform Utilities')
                    ->sort(10)
                    ->visible(fn () => self::userCanAccessTool('tooling.pulse')),
                NavigationItem::make('Laravel Horizon')
                    ->url(fn () => url('/horizon'), shouldOpenInNewTab: true)
                    ->icon('heroicon-o-queue-list')
                    ->group('Platform Utilities')
                    ->sort(11)
                    ->visible(fn () => self::userCanAccessTool('tooling.horizon')),
                NavigationItem::make('Laravel Telescope')
                    ->url(fn () => url('/telescope'), shouldOpenInNewTab: true)
                    ->icon('heroicon-o-bug-ant')
                    ->group('Platform Utilities')
                    ->sort(12)
                    ->visible(fn () => self::userCanAccessTool('tooling.telescope')),

                // Control Panels group — external service dashboards
                // running alongside the app in their own containers.
                // Each opens in a new tab (it's not a Filament surface)
                // and points at the service's native admin UI on the
                // host port. Super-admin only by default — operators
                // don't need to see the raw infrastructure.
                //
                // Host-based URLs use `request()->getHost()` so they
                // work when the admin panel is reached on localhost,
                // LAN IP, or a real hostname without hardcoding any
                // of them — whatever host loaded the admin page is
                // the host the browser already has cert trust for.
                NavigationItem::make('Grafana')
                    // Points at Laravel's reverse-proxy route,
                    // NOT the raw Grafana host port. Every
                    // request flows through /admin/grafana/* so
                    // the session check runs on each hit and
                    // X-WEBAUTH-USER gets injected for the
                    // auth-proxy trust chain.
                    ->url(fn () => route('admin.grafana.forward'), shouldOpenInNewTab: true)
                    ->icon('heroicon-o-presentation-chart-line')
                    ->group('Control Panels')
                    ->sort(10)
                    ->visible(fn () => self::userCanAccessTool('tooling.grafana')),
                NavigationItem::make('Prometheus')
                    ->url(fn () => route('admin.prometheus.forward'), shouldOpenInNewTab: true)
                    ->icon('heroicon-o-fire')
                    ->group('Control Panels')
                    ->sort(11)
                    ->visible(fn () => self::userCanAccessTool('tooling.prometheus')),
                NavigationItem::make('HAProxy Stats')
                    // Native HAProxy stats page — per-frontend and
                    // per-server UP/DOWN, request rates, queue
                    // depth. The Failover page surfaces a curated
                    // view for the common actions; this is the
                    // raw panel for deep debugging.
                    ->url(fn () => route('admin.haproxy-stats.forward', ['path' => 'stats']), shouldOpenInNewTab: true)
                    ->icon('heroicon-o-arrows-right-left')
                    ->group('Control Panels')
                    ->sort(11)
                    ->visible(fn () => self::userCanAccessTool('tooling.haproxy_stats')),
                NavigationItem::make('SeaweedFS Filer')
                    ->url(fn () => route('admin.seaweedfs.filer'), shouldOpenInNewTab: true)
                    ->icon('heroicon-o-archive-box')
                    ->group('Control Panels')
                    ->sort(12)
                    ->visible(fn () => self::userCanAccessTool('tooling.seaweedfs')),
                NavigationItem::make('SeaweedFS Master')
                    ->url(fn () => route('admin.seaweedfs.master'), shouldOpenInNewTab: true)
                    ->icon('heroicon-o-server-stack')
                    ->group('Control Panels')
                    ->sort(13)
                    ->visible(fn () => self::userCanAccessTool('tooling.seaweedfs')),
                NavigationItem::make('pgAdmin')
                    // Direct HTTPS — pgAdmin serves its own TLS
                    // from the shared cert volume. It's the one
                    // control panel NOT proxied through Laravel
                    // because Flask's OAuth2 session flow breaks
                    // through a reverse proxy.
                    ->url(fn () => 'https://'.self::canonicalHost().':5050', shouldOpenInNewTab: true)
                    ->icon('heroicon-o-circle-stack')
                    ->group('Control Panels')
                    ->sort(14)
                    ->visible(fn () => self::userCanAccessTool('tooling.pgadmin')),
                NavigationItem::make('Redis Commander')
                    // Proxied through Laravel with Basic auth
                    // injection (same pattern as Icecast). Replaced
                    // the earlier JWT SSO redirect which broke under
                    // HTTPS (mixed-content redirect to HTTP port).
                    ->url(fn () => route('admin.redis-commander.forward'), shouldOpenInNewTab: true)
                    ->icon('heroicon-o-bolt')
                    ->group('Control Panels')
                    ->sort(15)
                    ->visible(fn () => self::userCanAccessTool('tooling.redis_commander')),
                NavigationItem::make('Mailpit')
                    // Debug mail trap — always running but only in
                    // the mail path when MAIL_HOST=mailpit. In prod,
                    // MAIL_HOST points at the real relay and Mailpit
                    // sits idle until an operator flips it for
                    // debugging. Proxied through Laravel for TLS +
                    // session auth since Mailpit has no built-in auth.
                    ->url(fn () => route('admin.mailpit.forward'), shouldOpenInNewTab: true)
                    ->icon('heroicon-o-envelope-open')
                    ->group('Control Panels')
                    ->sort(16)
                    ->visible(fn () => self::userCanAccessTool('tooling.mailpit')),
                NavigationItem::make('Icecast')
                    ->url(fn () => route('admin.icecast.forward'), shouldOpenInNewTab: true)
                    ->icon('heroicon-o-musical-note')
                    ->group('Control Panels')
                    ->sort(17)
                    ->visible(fn () => self::userCanAccessTool('tooling.icecast')),
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
                // /login. Client portal users hitting an admin
                // route would get bounced by PanelRedirect anyway,
                // but we don't want a colored bar flashing during
                // the redirect either — `hasAnyPlatformRole()`
                // returns false for client-only users.
                fn (): string => auth()->user()?->hasAnyPlatformRole()
                    ? Blade::render('@livewire(\App\Livewire\SystemStatusBar::class)')
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

    /**
     * Canonical browser-side host for direct-to-service control
     * panel links. Pulled from `config('app.url')` instead of the
     * current request's host because Laravel-generated URLs (via
     * `route()`) also use `app.url` — if the user happens to be
     * browsing via localhost but the nav items use orbital.test,
     * clicking any Laravel-proxied item would break. Keeping all
     * nav URLs consistent with `app.url` means "add one /etc/hosts
     * entry and everything works" rather than "some links work,
     * others don't, depending on how you got here."
     */
    private static function canonicalHost(): string
    {
        return parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';
    }
}
