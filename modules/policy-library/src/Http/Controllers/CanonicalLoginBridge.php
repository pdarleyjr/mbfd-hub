<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Http\Controllers;

use App\Http\Controllers\Auth\CanonicalLoginController;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

final class CanonicalLoginBridge
{
    public function show(Request $request): View|Response
    {
        $response = app()->call([app(CanonicalLoginController::class), 'create'], ['request' => $request]);
        if ($response instanceof View) {
            $response->with('loginAction', '/login');
        }

        return $response;
    }

    public function store(Request $request): Response
    {
        return app()->call([app(CanonicalLoginController::class), 'store'], ['request' => $request]);
    }
}
