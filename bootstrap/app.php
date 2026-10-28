<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveActiveRole;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetLocale::class,

            /*
             | Change register of 2026-08-26: one account may hold several roles, and
             | the one it is *acting as* gates the session.
             |
             | Appended to the web group so it runs after the session and
             | authentication are available and before any `role:` route middleware —
             | route middleware runs after the group, so `role:` sees the constrained
             | relation. Placed before `SecurityHeaders` for no reason other than that
             | a response filter belongs last.
             */
            ResolveActiveRole::class,

            // M11 hardening. A response filter, so every screen — including the
            // frozen milestones' — gets the headers without being edited.
            SecurityHeaders::class,
        ]);

        /*
         | Every other session of an account ends when its password changes
         | (business decision, 2026-10-26): `AuthenticateSession` keeps the password
         | hash in the session and logs the session out at its next request once
         | the stored hash no longer matches — a reset by an administrator included.
         */
        $middleware->authenticateSessions();

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
