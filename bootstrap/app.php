<?php

use App\Http\Middleware\AppendOidcIdToken;
use App\Http\Middleware\ApplyUserPreferences;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\CheckToolPermission;
use App\Http\Middleware\PanelRedirect;
use App\Http\Middleware\RecordHttpMetrics;
use App\Http\Middleware\RequirePlatformRole;
use App\Http\Middleware\SetPermissionsTeamContext;
use App\Http\Middleware\TraceRequest;
use App\Http\Middleware\VerifyInboundMailToken;
use App\Support\Observability;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration as SentryIntegration;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

        // Request rate + duration metrics for every surface. Appended to
        // both stacks so API traffic (agent worker, webhooks, LiveKit) is
        // measured alongside the panels — those are the requests most
        // likely to be failing silently. Recording happens in terminate(),
        // after the response is flushed.
        $middleware->appendToGroup('web', RecordHttpMetrics::class);
        $middleware->appendToGroup('api', RecordHttpMetrics::class);

        // Distributed tracing, when it's switched on. Sits beside the
        // metrics middleware and shares its rules — same surface labels,
        // same /metrics and /up exemption, same "do the work in
        // terminate() so observing a request never costs the user
        // latency". A no-op with tracing disabled.
        $middleware->appendToGroup('web', TraceRequest::class);
        $middleware->appendToGroup('api', TraceRequest::class);

        $middleware->web(append: [
            SetPermissionsTeamContext::class,
            ApplyUserPreferences::class,
            // OIDC id_token injection for Passport's /oauth/token
            // endpoint. No-ops on every other route — see the
            // middleware class docblock.
            AppendOidcIdToken::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'check.role' => CheckRole::class,
            'platform.role' => RequirePlatformRole::class,
            'panel.redirect' => PanelRedirect::class,
            'inbound-mail-token' => VerifyInboundMailToken::class,
            // Gates the SSO entry points on the tooling.* permission
            // pattern shared with `AdminPanelProvider::userCanAccessTool`.
            'tool' => CheckToolPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Ship unhandled exceptions to the operator's error project
        // (GlitchTip, Sentry, anything speaking the same ingest API).
        //
        // Guarded rather than unconditional: with no integration
        // configured this must not install a reporter at all, and
        // Integration::handles() would otherwise wire a client that
        // silently discards everything. What gets sent, and what is
        // stripped first, is decided in ObservabilityServiceProvider.
        if (Observability::errorsEnabled()) {
            SentryIntegration::handles($exceptions);
        }

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
        $exceptions->render(function (HttpException $e, Request $request) {
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
