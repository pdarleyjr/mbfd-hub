<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class VerifyBidFederationToken
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.bid.federation_token');

        if (! is_string($expected) || $expected === '') {
            $this->logFailure($request, 'unconfigured');

            return new JsonResponse(['error' => 'bid_federation_unavailable'], 503);
        }

        $provided = $request->bearerToken();

        if (! is_string($provided) || ! hash_equals($expected, $provided)) {
            $this->logFailure($request, 'invalid_service_credential');

            return new JsonResponse(['error' => 'invalid_service_credential'], 401);
        }

        return $next($request);
    }

    private function logFailure(Request $request, string $category): void
    {
        Log::info('bid.federation.service_auth', [
            'result' => 'failure',
            'category' => $category,
            'operation' => $request->is('api/v2/bid/auth/exchange') ? 'exchange' : 'revalidation',
        ]);
    }
}
