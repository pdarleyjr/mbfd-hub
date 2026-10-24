<?php

namespace App\Http\Middleware;

use App\Support\Release\DeployedRelease;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddBuildHeaders
{
    public function __construct(private readonly ?string $applicationBasePath = null) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $response->headers->set('X-App-Commit', (new DeployedRelease($this->applicationBasePath))->commit());
        } catch (\Throwable) {
            $response->headers->set('X-App-Commit', DeployedRelease::UNKNOWN);
        }

        return $response;
    }
}
