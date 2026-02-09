<?php

namespace App\Http\Responses;

use Filament\Http\Responses\Auth\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Http\RedirectResponse;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        $user = auth()->user();

        // Users with training roles → redirect to training panel by default
        // (even if they also have super_admin, their primary workspace is training)
        $hasTrainingRole = $user
            && ($user->hasRole('training_admin') || $user->hasRole('training_viewer'));

        if ($hasTrainingRole) {
            return redirect()->to('/training');
        }

        // Default: redirect to admin panel (for super_admin-only or admin users)
        return redirect()->intended(filament()->getUrl());
    }
}
