<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Communications\InboundEmailAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InboundEmailController extends Controller
{
    public function __invoke(Request $request, InboundEmailAuthenticator $authenticator): JsonResponse
    {
        $email = $authenticator->persist($request);

        return response()->json(['id' => $email->getKey()], 201);
    }
}
