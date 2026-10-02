<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LibrarySecurity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Cache-Control', $response->headers->get('Content-Type') === 'application/pdf' ? $response->headers->get('Cache-Control', 'private, max-age=300, must-revalidate') : 'private, no-store');
        $response->headers->set('CDN-Cache-Control', 'no-store');
        $response->headers->set('Cloudflare-CDN-Cache-Control', 'no-store');
        if (! $request->is('login')) {
            $scripts = $request->is('manage', 'manage/*', 'livewire/*') ? "'unsafe-inline' 'unsafe-eval'" : "'wasm-unsafe-eval'";
            $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self' ".$scripts."; style-src 'self' 'unsafe-inline' https://fonts.bunny.net; font-src 'self' https://fonts.bunny.net data:; img-src 'self' data: blob:; connect-src 'self'; worker-src 'self' blob:; object-src 'none'; frame-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
        }

        return $response;
    }
}
