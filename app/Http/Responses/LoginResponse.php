<?php

namespace App\Http\Responses;

use Filament\Http\Responses\Auth\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Http\RedirectResponse;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        $user = auth()->user();

        // Training-only users (no super_admin or admin role) → redirect to training panel
        $isTrainingOnly = $user
            && ($user->hasRole('training_admin') || $user->hasRole('training_viewer'))
            && !$user->hasRole('super_admin')
            && !$user->hasRole('admin');

        if ($isTrainingOnly) {
            return redirect()->to('/training');
        }

        // Default: redirect to admin panel
        return redirect()->intended(filament()->getUrl());
    }
}
