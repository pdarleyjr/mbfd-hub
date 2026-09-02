<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that gates the bid-bridge endpoints (e.g.
 * /api/v2/verify-credentials) with a shared bearer token.
 *
 * The bid Cloudflare Worker holds the matching token in its
 * `PORTAL_BID_READER` env var. The Hub compares against
 * `config('services.bid.reader_token')`. Uses hash_equals() for
 * timing-safe comparison.
 *
 * Returns 401 if the Authorization header is missing, malformed, or
 * the token doesn't match. Returns 503 if the portal hasn't configured
 * a token (defense against an open endpoint by misconfiguration).
 */
class VerifyBidReaderToken
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) (config('services.bid.reader_token') ?? '');
        if ($expected === '') {
            // Fail closed: an unconfigured token means the endpoint is not
            // intentionally exposed; refuse all callers.
            return new JsonResponse(['error' => 'bridge_disabled'], 503);
        }

        $header = (string) $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            return new JsonResponse(['error' => 'missing_token'], 401);
        }
        $presented = trim(substr($header, 7));
        if ($presented === '' || ! hash_equals($expected, $presented)) {
            return new JsonResponse(['error' => 'invalid_token'], 401);
        }

        return $next($request);
    }
}
