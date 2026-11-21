<?php

use App\Http\Middleware\CardTokenOnly;
use App\Http\Middleware\RejectBearerToken;
use App\Systems\Odoo\Exceptions\OdooAccessDenied;
use App\Systems\Odoo\Exceptions\OdooNotFound;
use App\Systems\Odoo\Exceptions\OdooRejected;
use App\Systems\Odoo\Exceptions\OdooUnreachable;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Psr\Log\LogLevel;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware([RejectBearerToken::class, CardTokenOnly::class, SubstituteBindings::class])
                ->group(base_path('routes/card.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind the Funnel, the OAuth discovery documents and the card's domain come from the forwarded host.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->level(OdooUnreachable::class, LogLevel::WARNING);
        $exceptions->level(OdooAccessDenied::class, LogLevel::INFO);
        $exceptions->level(OdooNotFound::class, LogLevel::NOTICE);
        $exceptions->level(OdooRejected::class, LogLevel::NOTICE);

        // /mcp answers JSON whatever the client accepts, so a missing token is a 401 and never a redirect to the sign-in.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('card/*', 'mcp') || $request->expectsJson(),
        );
    })->create();
