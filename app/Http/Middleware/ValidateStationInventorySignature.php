<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates the PIN-issued Station Inventory V2 signature for every protected route.
 *
 * Item and supply-request operations reuse the signature issued for the base
 * inventory URL. Laravel's ordinary signed middleware cannot validate those
 * nested paths directly, so this guard reconstructs only the existing base
 * path shapes before performing Laravel's normal signature validation.
 */
final class ValidateStationInventorySignature
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->hasValidSignature($request)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid or expired token',
            ], 401);
        }

        return $next($request);
    }

    private function hasValidSignature(Request $request): bool
    {
        if ($request->hasValidSignature()) {
            return true;
        }

        $url = $request->fullUrl();
        $baseUrl = preg_replace(
            '#/station-inventory/(\d+)/(supply-requests|item/\d+)#',
            '/station-inventory/$1',
            $url,
        );

        return is_string($baseUrl)
            && $baseUrl !== $url
            && URL::hasValidSignature(Request::create($baseUrl));
    }
}
