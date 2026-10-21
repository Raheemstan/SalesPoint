<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Pilot lock. Prepended to the very front of the GLOBAL stack (not just the
         * 'web' group) so it runs before session, CSRF and auth - an expired pilot
         * blocks literally every request, including the login page and the API,
         * not just the screens behind a session. A no-op (single config() read)
         * on any install that doesn't have PILOT_MODE=true.
         */
        $middleware->prepend(\App\Http\Middleware\EnforcePilotLock::class);

        // Spatie Laravel-Permission middleware aliases. Without these,
        // `role:admin|manager` on a route throws "Target class [role] does not exist".
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);

        /*
         * Deactivating a user has to take effect on their *next request*, not
         * whenever their session happens to expire, so this runs on every web
         * request rather than being hung off the login screen. Appended, so it
         * sits after StartSession and can see who is signed in.
         */
        $middleware->appendToGroup('web', \App\Http\Middleware\EnsureUserIsActive::class);

        /*
         * Which branch this request is acting as. Appended, so it runs after
         * StartSession and after authentication - it needs both the signed-in
         * user and their remembered choice, and neither exists earlier.
         *
         * After EnsureUserIsActive on purpose: a deactivated user is turned away
         * before anything bothers working out where they are standing.
         */
        $middleware->appendToGroup('web', \App\Http\Middleware\SetActiveBranch::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
