<?php

use App\Services\OperationalForms\FrocImportLimits;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Sentry\Laravel\Integration;

$app = Application::configure(basePath: dirname(__DIR__))
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
        $middleware->statefulApi();
        $middleware->web(append: [
            \App\Http\Middleware\AddBuildHeaders::class,
            \App\Http\Middleware\EnsureCanonicalSessionIsCurrent::class,
            \App\Http\Middleware\ForcePasswordChange::class,
            \App\Http\Middleware\SetCacheHeaders::class,
        ]);

        $middleware->alias([
            'admin.role' => \App\Http\Middleware\EnsureAdminApiRole::class,
            'workgroup.access' => \App\Http\Middleware\EnsureWorkgroupPanelAccess::class,
            'workgroup.global' => \App\Http\Middleware\EnsureGlobalWorkgroupAccess::class,
            'verify.bid.token' => \App\Http\Middleware\VerifyBidReaderToken::class,
            'verify.media-control.token' => \App\Http\Middleware\VerifyMediaControlServiceToken::class,
            'station-inventory.signed' => \App\Http\Middleware\ValidateStationInventorySignature::class,
            'display.readonly' => \App\Http\Middleware\EnsureDisplayReadOnly::class,
            'display.token' => \App\Http\Middleware\EnsureDisplayToken::class,
            'conference.enabled' => \App\Http\Middleware\EnsureVideoConferencingEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Render a stable, user-readable JSON contract for Operational Forms API
        // requests (or any JSON request) that exceed the server's request-size
        // limit (HTTP 413). This is thrown by the ValidatePostSize middleware
        // before Laravel validation runs, so the React API client must not
        // receive an HTML error page.
        //
        // Scoped to the Operational Forms API (and any JSON request) so unrelated
        // web error handling and unrelated exceptions are left untouched. For a
        // normal, non-API, non-JSON web request we fall through to a standard
        // HTML 413 page and never convert it to the F-ROC JSON schema.
        $exceptions->render(function (\Illuminate\Http\Exceptions\PostTooLargeException $exception, \Illuminate\Http\Request $request) {
            if (! $request->is('employee/forms/api/*') && ! $request->expectsJson()) {
                return response()->make(
                    '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>413 Payload Too Large</title></head>'
                        .'<body><h1>413</h1><p>The uploaded file is too large for this request.</p></body></html>',
                    413,
                    ['Content-Type' => 'text/html; charset=utf-8'],
                );
            }

            $megabytes = FrocImportLimits::uploadMaxMegabytes();

            return response()->json([
                'message' => "The upload was rejected before analysis because it exceeded the server’s request limit. The F-ROC importer accepts ZIP or TXT files up to {$megabytes} MB. Contact Forms administration if this file is below that size.",
                'code' => 'request_too_large',
            ], 413);
        });

        Integration::handles($exceptions);
    })->create();

if (defined('MBFD_PHPUNIT_BOOTSTRAP')) {
    $app->loadEnvironmentFrom('tests/Fixtures/phpunit.environment');
}

return $app;
