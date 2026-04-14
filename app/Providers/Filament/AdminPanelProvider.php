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
                'primary' => Color::Indigo,
                'danger' => Color::Red,
                'warning' => Color::Amber,
                'success' => Color::Emerald,
            ])
            ->navigationGroups([
                'Platform',
                'Telephony',
                'Conversational AI',
                'Monitor',
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([])
            ->widgets([])
            ->userMenuItems([
                // Cross-surface jumps. Admin, operator, and portal are
                // separate Filament panels sharing one user-menu shell
                // — these links let a super-admin hop between them
                // without re-logging-in, and operators/supervisors can
                // jump to the softphone workspace.
                MenuItem::make()
                    ->label('Operator Workspace')
                    ->url(fn () => url('/operator'))
                    ->icon('heroicon-o-device-phone-mobile')
                    ->visible(fn () => auth()->user()?->hasAnyPlatformRole() ?? false),
                MenuItem::make()
                    ->label('Customer Portal')
                    ->url(fn () => url('/portal'))
                    ->icon('heroicon-o-globe-alt')
                    // Only visible when the current user is attached to
                    // at least one real tenant team (dog-fooding). Super
                    // admin alone isn't enough — the portal is a tenant
                    // surface and a super-admin with no tenant membership
                    // has nothing meaningful to see there.
                    ->visible(fn () => auth()->user()?->belongsToAnyTenant() ?? false),
            ])
            ->navigationItems([
                // Monitor group order:
                //   1. Call Logs        (CallLogResource sort=1)
                //   2. Logs & Metrics   Grafana
                //   3. User Activity    Pulse
                //   4. Queue Workers    Horizon
                //   5. Application Debug Telescope
                NavigationItem::make('Logs & Metrics')
                    ->url(fn () => 'http://'.request()->getHost().':3000', shouldOpenInNewTab: true)
                    ->icon('heroicon-o-presentation-chart-line')
                    ->group('Monitor')
                    ->sort(20)
                    ->visible(fn () => self::userCanAccessTool('tooling.grafana')),
                NavigationItem::make('User Activity')
                    ->url(fn () => url('/pulse'), shouldOpenInNewTab: true)
                    ->icon('heroicon-o-chart-bar-square')
                    ->group('Monitor')
                    ->sort(21)
                    ->visible(fn () => self::userCanAccessTool('tooling.pulse')),
                NavigationItem::make('Queue Workers')
                    ->url(fn () => url('/horizon'), shouldOpenInNewTab: true)
                    ->icon('heroicon-o-queue-list')
                    ->group('Monitor')
                    ->sort(22)
                    ->visible(fn () => self::userCanAccessTool('tooling.horizon')),
                NavigationItem::make('Application Debug')
                    ->url(fn () => url('/telescope'), shouldOpenInNewTab: true)
                    ->icon('heroicon-o-magnifying-glass')
                    ->group('Monitor')
                    ->sort(23)
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
                fn (): string => $this->renderStatusBar(),
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
     * Read the cached system health snapshot and render a thin colored bar at
     * the very top of the page: green = healthy, amber = degraded, red = outage.
     * Reads cache only — never triggers fresh probes — so this is free on every
     * page load. Hidden until the cache has been populated by a dashboard visit.
     */
    private function renderStatusBar(): string
    {
        $checks = \Illuminate\Support\Facades\Cache::get('system_health:checks');
        if (! is_array($checks) || empty($checks)) {
            return '';
        }

        $down = 0;
        $warn = 0;
        foreach ($checks as $c) {
            if ($c->status === 'down') {
                $down++;
            } elseif ($c->status === 'warn') {
                $warn++;
            }
        }

        [$cls, $title] = match (true) {
            $down > 0 => ['is-down', "{$down} service(s) down".($warn ? ", {$warn} degraded" : '')],
            $warn > 0 => ['is-warn', "{$warn} service(s) degraded"],
            default => ['is-ok', 'All systems operational'],
        };

        return <<<HTML
            <div class="orbital-status-bar {$cls}" title="{$title}" aria-label="{$title}"></div>
        HTML;
    }
}
