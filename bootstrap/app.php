<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Trust Cloudflare Tunnel proxy (runs on localhost) and Docker bridge network
        $middleware->trustProxies(
            at: ['127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],
            headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR |
                     \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST |
                     \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT |
                     \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO,
        );
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->web(append: [
            \App\Http\Middleware\AddBuildHeaders::class,
            \App\Http\Middleware\ForcePasswordChange::class,
            \App\Http\Middleware\SetCacheHeaders::class,
        ]);

        $middleware->alias([
            'admin.role' => \App\Http\Middleware\EnsureAdminApiRole::class,
            'verify.bid.token' => \App\Http\Middleware\VerifyBidReaderToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        Integration::handles($exceptions);
    })->create();
