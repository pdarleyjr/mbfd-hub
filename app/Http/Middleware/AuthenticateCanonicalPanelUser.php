<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Filament\Http\Middleware\Authenticate;

final class AuthenticateCanonicalPanelUser extends Authenticate
{
    protected function redirectTo($request): ?string
    {
        return route('login');
    }
}
