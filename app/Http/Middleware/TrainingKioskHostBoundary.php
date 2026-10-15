<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Isolate the shared kiosk's cookies and routes before session/auth middleware. */
final class TrainingKioskHostBoundary
{
    public function handle(Request $request, Closure $next): Response
    {
        if (strcasecmp($request->getHost(), (string) config('training-roster.host')) !== 0) {
            return $next($request);
        }

        if (config('training-roster.scheme') === 'https' && ! $request->isSecure()) {
            return redirect()->to('https://'.config('training-roster.host').$request->getRequestUri(), 308);
        }

        abort_unless($request->is('/') || $request->is('catalog') || $request->is('csrf') || $request->is('courses/*/start') || $request->is('r/*'), 404);
        // Never flash submitted credentials/identifiers into the shared session,
        // including when a non-JavaScript client omits its JSON Accept header.
        if ($request->is('r/*/submit')) {
            $request->headers->set('Accept', 'application/json');
        }
        config([
            'session.cookie' => 'mbfd_training_kiosk',
            'session.domain' => null,
            'session.http_only' => true,
            'session.same_site' => 'strict',
        ]);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'; form-action 'self'");

        return $response;
    }
}
