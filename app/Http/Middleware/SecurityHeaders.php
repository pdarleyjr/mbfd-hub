<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // X-Frame-Options is deprecated and only accepts DENY|SAMEORIGIN — it can't
        // express "self + cloud.mbfdhub.com". The CSP `frame-ancestors` directive
        // below covers the same use case and supersedes this header in all modern
        // browsers, so we omit it intentionally.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        if ($request->secure()) {
            // HSTS preload is also set at the Cloudflare zone level (1-year, includeSubDomains, preload).
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        // Content-Security-Policy — ENFORCING (promoted from Report-Only 2026-05-20).
        //
        // This permissive baseline is calibrated for Filament v3 (inline Alpine.js
        // attributes, Livewire hydration scripts), Vite's HMR client in dev, Reverb
        // WebSockets, Tailwind injected styles, Cloudflare Insights, and the React
        // SPAs under /daily/* /pump-simulator/* /apparatus-layout/*.
        //
        // Post-promotion monitoring: watch Sentry for CSP violation reports.
        //   docker exec mbfd-hub-laravel grep -F '[CSP]' storage/logs/laravel-$(date +%F).log
        $cspParts = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://static.cloudflareinsights.com https://www.googletagmanager.com https://pulsepoint.org https://web.pulsepoint.org",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net https://fonts.googleapis.com",
            "font-src 'self' data: https://fonts.bunny.net https://fonts.googleapis.com https://fonts.gstatic.com",
            "img-src 'self' data: blob: https:",
            "media-src 'self' blob: https:",
            "connect-src 'self' wss: https://api.pulsepoint.org https://web.pulsepoint.org https://static.cloudflareinsights.com",
            "frame-src 'self' https://www.pulsepoint.org https://web.pulsepoint.org https://baserow.mbfdhub.com https://inventory.mbfdhub.com",
            // Allow cloud.mbfdhub.com (Nextcloud) to embed this site as an
            // External Sites iframe. All other origins remain blocked.
            "frame-ancestors 'self' https://cloud.mbfdhub.com",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "worker-src 'self' blob:",
            "manifest-src 'self'",
            "upgrade-insecure-requests",
            // Legacy report-uri — supported by every browser. Reporting API v1
            // (report-to + Report-To header) is more powerful but adds a second
            // header and isn't supported by Safari yet; report-uri is enough for
            // an enforcement CSP.
            'report-uri /_csp-report',
        ];
        $response->headers->set('Content-Security-Policy', implode('; ', $cspParts));

        // Strip X-Powered-By from BOTH the Symfony response bag (covers PHP-FPM)
        // AND PHP's SAPI-level header stack (covers the php artisan serve dev
        // server used by Sail, which auto-adds the header from expose_php=On at
        // a layer below the response bag).
        $response->headers->remove('X-Powered-By');
        if (! headers_sent() && function_exists('header_remove')) {
            header_remove('X-Powered-By');
        }

        return $response;
    }
}
