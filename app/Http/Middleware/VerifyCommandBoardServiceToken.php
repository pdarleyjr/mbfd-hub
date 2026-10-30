<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates Command Board -> Hub internal calls with the same service
 * bearer token the Command PIN channel uses (services.command_board.service_token).
 * Both sides are hashed first so the compare is constant-time and length-blind.
 */
final class VerifyCommandBoardServiceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.command_board.service_token');

        if (! is_string($expected) || trim($expected) === '') {
            return $this->reject(['error' => 'command_board_link_not_configured'], 503);
        }

        $provided = $request->bearerToken();

        if (! is_string($provided) || ! hash_equals(hash('sha256', $expected), hash('sha256', $provided))) {
            return $this->reject(['error' => 'invalid_service_credential'], 401);
        }

        return $next($request);
    }

    /** @param  array<string, string>  $body */
    private function reject(array $body, int $status): JsonResponse
    {
        return new JsonResponse($body, $status, ['Cache-Control' => 'no-store, private']);
    }
}
