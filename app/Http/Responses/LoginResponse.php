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

        // Users with training roles → redirect to training panel
        $hasTrainingRole = $user
            && ($user->hasRole('training_admin') || $user->hasRole('training_viewer'));

        if ($hasTrainingRole) {
            return new RedirectResponse(url('/training'));
        }

        // Default: redirect to admin panel
        return new RedirectResponse(url(filament()->getUrl()));
    }
}
