<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyMediaControlServiceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.media_control.authorization.service_token');

        if (! is_string($expected) || $expected === '') {
            return new JsonResponse(['error' => 'media_control_federation_unavailable'], 503);
        }

        $provided = $request->bearerToken();

        if (! is_string($provided) || ! hash_equals($expected, $provided)) {
            return new JsonResponse(['error' => 'invalid_service_credential'], 401);
        }

        return $next($request);
    }
}
