<?php

declare(strict_types=1);

namespace App\Http\Middleware\UnitStatus;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;

/**
 * Used in place of `auth:web` on the Unit Status refresh routes. The open page refreshes itself with fetch(); when the
 * session has ended, the login redirect would be followed silently and look like a broken board, so a background
 * refresh (marked by the page) gets a plain 401 it can act on. Everything else is handed to the application's own
 * `auth` middleware untouched, so normal page loads still redirect to login and no global behaviour changes.
 *
 * It wraps `auth` instead of sitting before it because Laravel sorts authentication middleware ahead of everything else.
 */
final class SignedOutBackgroundRefresh
{
    public const HEADER = 'X-Hub-Background-Refresh';

    public function __construct(private readonly Router $router) {}

    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        $guards = $guards === [] ? ['web'] : $guards;
        if ($request->header(self::HEADER) === '1' && $request->user($guards[0]) === null) {
            return response()->json(['error' => 'signed_out'], 401, ['Cache-Control' => 'no-store, private']);
        }

        $auth = app($this->router->getMiddleware()['auth']);

        return $auth->handle($request, $next, ...$guards);
    }
}
