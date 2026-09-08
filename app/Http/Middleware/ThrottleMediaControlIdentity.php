<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final class ThrottleMediaControlIdentity
{
    public function handle(Request $request, Closure $next): Response
    {
        // These two endpoints accept exactly one dedicated machine client. Run
        // after VerifyMediaControlServiceToken; never key on submitted identity
        // fields or store its bearer credential in the limiter cache.
        $key = 'federation-identity-client:media-control';
        if (! RateLimiter::attempt($key, 6000, static fn (): bool => true, 60)) {
            return response()->json(['error' => 'rate_limit_exceeded'], 429, [
                'Retry-After' => (string) RateLimiter::availableIn($key),
                'Cache-Control' => 'no-store, private',
            ]);
        }

        return $next($request);
    }
}
