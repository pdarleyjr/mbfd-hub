<?php

namespace App\Http\Responses;

use Filament\Http\Responses\Auth\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): Response
    {
        $user = auth()->user();

        // Admin/super_admin roles take priority → redirect to admin panel
        if ($user && $user->hasAnyRole(['super_admin', 'admin'])) {
            return new RedirectResponse(url('/admin'));
        }

        // Users with ONLY training roles → redirect to training panel
        if ($user && $user->hasAnyRole(['training_admin', 'training_viewer'])) {
            return new RedirectResponse(url('/training'));
        }

        // Default: redirect to admin panel
        return new RedirectResponse(url(filament()->getUrl()));
    }
}
