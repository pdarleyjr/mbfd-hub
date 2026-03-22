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
        
        // Read git SHA from deploy-time cached file instead of shell_exec
        try {
            $shaFile = base_path('.git-sha');
            $sha = file_exists($shaFile) ? trim(file_get_contents($shaFile)) : 'unknown';
            $response->headers->set('X-App-Commit', $sha);
        } catch (\Throwable $e) {
            // Silently ignore if headers can't be set
        }
        return $response;
    }
}
