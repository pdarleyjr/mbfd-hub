<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hard guard for the read-only command-display API.
 *
 * The /api/display/* surface is GET-only by design: it feeds a separate,
 * staff-only command-display dashboard (gated by Cloudflare Access at the
 * edge) and must never mutate Hub data. This middleware rejects any verb
 * other than GET/HEAD/OPTIONS with a 405 before the request reaches a
 * controller, providing a second line of defence on top of the route
 * definitions themselves only registering GET routes.
 */
final class EnsureDisplayReadOnly
{
    /**
     * @var list<string>
     */
    private const ALLOWED_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->getMethod(), self::ALLOWED_METHODS, true)) {
            return response()->json(
                ['message' => 'Method Not Allowed. Display API is read-only.'],
                Response::HTTP_METHOD_NOT_ALLOWED,
                ['Allow' => 'GET, HEAD, OPTIONS']
            );
        }

        return $next($request);
    }
}
