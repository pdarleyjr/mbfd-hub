<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AddBuildHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        
        // Use headers->set() which works on all response types
        try {
            $sha = cache()->remember('build_sha', 60, fn() => trim(shell_exec('git rev-parse HEAD') ?? 'unknown'));
            $response->headers->set('X-App-Commit', $sha);
        } catch (\Throwable $e) {
            // Silently ignore if headers can't be set
        }
        return $response;
    }
}
