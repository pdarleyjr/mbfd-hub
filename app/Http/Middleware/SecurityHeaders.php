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
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        if ($request->secure()) {
            // HSTS preload is also set at the Cloudflare zone level (1-year, includeSubDomains, preload).
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        // Content-Security-Policy — REPORT-ONLY for now.
        //
        // This permissive baseline is calibrated for Filament v3 (inline Alpine.js
        // attributes, Livewire hydration scripts), Vite's HMR client in dev, Reverb
        // WebSockets, Tailwind injected styles, Cloudflare Insights, and the React
        // SPAs under /daily/* /pump-simulator/* /apparatus-layout/*.
        //
        // Promote to enforcing `Content-Security-Policy` ONLY after the
        // `Content-Security-Policy-Report-Only` header has been live for 1+ week with
        // zero unexpected violations in the report stream. Filament Livewire payloads
        // and PulsePoint embeds are the most likely places to need tuning.
        $cspParts = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://static.cloudflareinsights.com https://www.googletagmanager.com https://pulsepoint.org https://web.pulsepoint.org",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net https://fonts.googleapis.com",
            "font-src 'self' data: https://fonts.bunny.net https://fonts.googleapis.com https://fonts.gstatic.com",
            "img-src 'self' data: blob: https:",
            "media-src 'self' blob: https:",
            "connect-src 'self' wss: https://api.pulsepoint.org https://web.pulsepoint.org https://static.cloudflareinsights.com",
            "frame-src 'self' https://www.pulsepoint.org https://web.pulsepoint.org https://baserow.mbfdhub.com https://inventory.mbfdhub.com",
            "frame-ancestors 'self'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "worker-src 'self' blob:",
            "manifest-src 'self'",
            "upgrade-insecure-requests",
        ];
        $response->headers->set('Content-Security-Policy-Report-Only', implode('; ', $cspParts));

        $response->headers->remove('X-Powered-By');

        return $response;
    }
}
