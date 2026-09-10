<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateOutsideMemberBootstrap extends Authenticate
{
    public function handle($request, Closure $next, ...$guards): Response
    {
        if ($request->hasSession()) {
            return app(EnforceMemberBootstrapBoundary::class)->handle(
                $request,
                fn (Request $request): Response => parent::handle($request, $next, ...$guards),
            );
        }

        return parent::handle($request, $next, ...$guards);
    }
}
