<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetCacheHeaders
{
    /**
     * Handle an incoming request and set appropriate Cache-Control headers.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // For /daily/assets/* - set long-term immutable cache
        if (str_starts_with($request->path(), 'daily/assets/')) {
            $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
        }
        // For /daily/index.html and /daily/ - no-store
        elseif (str_starts_with($request->path(), 'daily') && 
                (str_ends_with($request->path(), '.html') || $request->path() === 'daily')) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        return $response;
    }
}
