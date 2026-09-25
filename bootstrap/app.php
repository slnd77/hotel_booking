<?php

use App\Http\Middleware\AuthorizeApiScope;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);

        $middleware->validateCsrfTokens(except: [
            'properties/*/booking-engine/checkout/*',
        ]);

        $middleware->web(append: [
            EnsureUserIsActive::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->api(prepend: [
            ForceJsonResponse::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'api.scope' => AuthorizeApiScope::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Errors go to Sentry when SENTRY_LARAVEL_DSN is set (nothing is sent without it).
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
