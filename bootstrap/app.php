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
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // An API key arrives in the request body on every AI call. Without
        // this it would be flashed to the session — and rendered on the
        // debug error page — the first time a request threw.
        $exceptions->dontFlash([
            'api_key',
            'current_password',
            'password',
            'password_confirmation',
        ]);
    })->create();
