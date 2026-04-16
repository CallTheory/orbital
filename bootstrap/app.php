<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust nginx-tls as a reverse proxy. Without this, Laravel
        // sees every request as plain HTTP (because the internal hop
        // from nginx to the app IS HTTP on the sail network) and
        // generates http:// URLs, mismatches CSRF tokens, and drops
        // session cookies. Trusting '*' is safe because the app
        // container's port 80 is not host-bound — only nginx-tls
        // can reach it, and nginx sets X-Forwarded-* correctly.
        $middleware->trustProxies(at: '*');

        // Exempt the reverse-proxy routes from CSRF verification.
        // These routes forward raw HTTP to upstream services
        // (Grafana, Redis Commander, Prometheus, etc.) whose SPAs
        // make POST requests that don't carry Laravel CSRF tokens.
        // Auth is already handled by the session + tooling.*
        // permission middleware on each route, so CSRF is redundant
        // and would block every upstream SPA form submission.
        $middleware->validateCsrfTokens(except: [
            // Reverse-proxy routes — upstream SPAs don't carry
            // Laravel CSRF tokens on their POST requests.
            'admin/grafana/*',
            'admin/redis-commander/*',
            'admin/prometheus/*',
            'admin/pgadmin/*',
            'admin/seaweedfs/*',
            'admin/icecast/*',
            'admin/mailpit/*',
            // Passport OAuth2 endpoints — the authorize approve/deny
            // are POST forms initiated by external tools (pgAdmin,
            // MinIO Console) that don't have a CSRF token. The
            // token endpoint is an API call from the OAuth2 client.
            'oauth/*',
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\SetPermissionsTeamContext::class,
            \App\Http\Middleware\ApplyUserPreferences::class,
            // OIDC id_token injection for Passport's /oauth/token
            // endpoint. No-ops on every other route — see the
            // middleware class docblock.
            \App\Http\Middleware\AppendOidcIdToken::class,
        ]);

        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'check.role' => \App\Http\Middleware\CheckRole::class,
            'platform.role' => \App\Http\Middleware\RequirePlatformRole::class,
            'panel.redirect' => \App\Http\Middleware\PanelRedirect::class,
            'inbound-mail-token' => \App\Http\Middleware\VerifyInboundMailToken::class,
            // Gates the SSO entry points on the tooling.* permission
            // pattern shared with `AdminPanelProvider::userCanAccessTool`.
            'tool' => \App\Http\Middleware\CheckToolPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // When Filament's Authenticate middleware aborts with 403
        // because the user doesn't have canAccessPanel for the panel
        // they hit, redirect them to their actual home panel instead
        // of showing a forbidden error. This is the UX fallback for
        // "wrong panel" — a user who belongs somewhere else gets
        // silently routed there.
        //
        // Complementary to PanelRedirect middleware: the middleware
        // catches the case at request-entry time, and this handler
        // catches anything that slipped past (Filament's own
        // Authenticate::authenticate() throws 403 at a point our
        // middleware can't reliably intercept in every test path).
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, \Illuminate\Http\Request $request) {
            if ($e->getStatusCode() !== 403) {
                return null;
            }
            $user = $request->user();
            if (! $user) {
                return null;
            }
            $path = $request->path();
            $onPanelRoute = str_starts_with($path, 'admin')
                || str_starts_with($path, 'operator')
                || str_starts_with($path, 'portal');
            if (! $onPanelRoute) {
                return null;
            }
            $home = match (true) {
                method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin() => '/admin',
                method_exists($user, 'hasAnyPlatformRole') && $user->hasAnyPlatformRole() => '/operator',
                default => '/portal',
            };
            // Avoid redirect loops: if they're already on their home,
            // let the 403 bubble up (something else is wrong).
            $currentRoot = '/'.explode('/', $path)[0];
            if ($currentRoot === $home) {
                return null;
            }
            return redirect($home);
        });
    })->create();
