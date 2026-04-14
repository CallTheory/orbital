<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\SetPermissionsTeamContext::class,
        ]);

        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'check.role' => \App\Http\Middleware\CheckRole::class,
            'platform.role' => \App\Http\Middleware\RequirePlatformRole::class,
            'panel.redirect' => \App\Http\Middleware\PanelRedirect::class,
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
