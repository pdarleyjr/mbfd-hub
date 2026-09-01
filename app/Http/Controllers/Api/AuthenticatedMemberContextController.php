<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Identity\AuthenticatedMemberContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuthenticatedMemberContextController extends Controller
{
    public function __invoke(
        Request $request,
        AuthenticatedMemberContextResolver $resolver,
    ): JsonResponse {
        $response = response()->json($resolver->resolve($request)->toClientArray());
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Vary', 'Cookie');

        return $response;
    }
}
