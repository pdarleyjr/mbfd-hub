<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared-secret guard for the read-only command-display API.
 *
 * The /api/display/* surface is reached by the Cloudflare Functions edge gateway
 * (which fronts the staff-only command.mbfdhub.com display behind Cloudflare Access).
 * Because the Hub bypasses Access for /api, this middleware ensures the display
 * endpoints — including the staff-only personnel roster — are only reachable by a
 * caller that presents the shared secret in the X-Display-Token header.
 *
 * DISPLAY_API_TOKEN is required wherever these routes are exposed. If it is
 * absent, non-preflight requests fail closed before reaching a display endpoint.
 */
final class EnsureDisplayToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.display_api.token');

        // CORS preflight is never gated.
        if ($request->getMethod() === 'OPTIONS') {
            return $next($request);
        }

        if (! is_string($expected) || $expected === '') {
            return response()->json(
                ['message' => 'Display API is unavailable.'],
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        }

        $provided = (string) $request->header('X-Display-Token', '');

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(
                ['message' => 'Forbidden.'],
                Response::HTTP_FORBIDDEN
            );
        }

        return $next($request);
    }
}
