<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureVideoConferencingEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('video-conferencing.enabled')) {
            return response()->json([
                'message' => 'Video conferencing is not available yet.',
                'code' => 'conference_disabled',
            ], 503);
        }

        return $next($request);
    }
}
