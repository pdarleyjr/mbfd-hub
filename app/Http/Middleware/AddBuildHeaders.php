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
        
        // BinaryFileResponse doesn't support header() method
        if ($response instanceof BinaryFileResponse) {
            return $response;
        }
        
        $sha = cache()->remember('build_sha', 60, fn() => trim(shell_exec('git rev-parse HEAD') ?? 'unknown'));
        $response->header('X-App-Commit', $sha);
        return $response;
    }
}
