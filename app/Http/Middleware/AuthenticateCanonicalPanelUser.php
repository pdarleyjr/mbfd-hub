<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateCanonicalPanelUser extends Authenticate
{
    public function handle($request, Closure $next, ...$guards): Response
    {
        /** @var Request $request */
        return app(EnsureCanonicalSessionIsCurrent::class)->handle(
            $request,
            fn (Request $request): Response => parent::handle($request, $next, ...$guards),
        );
    }

    protected function redirectTo($request): string
    {
        return route('login');
    }
}
