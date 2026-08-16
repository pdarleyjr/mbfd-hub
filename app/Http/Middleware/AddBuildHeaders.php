<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

class AddBuildHeaders
{
    public function __construct(private readonly ?string $applicationBasePath = null) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $response->headers->set('X-App-Commit', $this->deployedCommit());
        } catch (\Throwable) {
            $response->headers->set('X-App-Commit', 'unknown');
        }

        return $response;
    }

    private function deployedCommit(): string
    {
        $basePath = $this->applicationBasePath ?? base_path();
        $markerPath = $basePath.'/public/deploy-marker.json';

        if (is_file($markerPath)) {
            try {
                $marker = json_decode((string) file_get_contents($markerPath), true, 512, JSON_THROW_ON_ERROR);
                $sha = is_array($marker) ? ($marker['sha'] ?? null) : null;
                if (is_string($sha) && preg_match('/^[0-9a-f]{40}$/i', $sha) === 1) {
                    return strtolower($sha);
                }
            } catch (JsonException) {
                // Fall back to the source snapshot when a partial marker write is observed.
            }
        }

        $shaPath = $basePath.'/.git-sha';
        if (is_file($shaPath)) {
            $sha = trim((string) file_get_contents($shaPath));
            if (preg_match('/^[0-9a-f]{40}$/i', $sha) === 1) {
                return strtolower($sha);
            }
        }

        return 'unknown';
    }
}
