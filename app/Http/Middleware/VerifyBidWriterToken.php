<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyBidWriterToken
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.bid.writer_token');
        if (! is_string($expected) || trim($expected) === '') {
            return new JsonResponse(['error' => 'bid_writer_unavailable'], 503);
        }

        // A mistaken secret reuse must fail closed even when the caller knows it.
        foreach (['reader_token', 'federation_token'] as $credential) {
            $other = config('services.bid.'.$credential);
            if (is_string($other) && $other !== '' && hash_equals($other, $expected)) {
                return new JsonResponse(['error' => 'bid_writer_unavailable'], 503);
            }
        }

        $provided = $request->bearerToken();
        if (! is_string($provided) || ! hash_equals($expected, $provided)) {
            return new JsonResponse(['error' => 'invalid_service_credential'], 401);
        }

        return $next($request);
    }
}
