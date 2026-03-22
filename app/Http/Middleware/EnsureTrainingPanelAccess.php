<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTrainingPanelAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $hasAccess = $user->hasRole('super_admin')
            || $user->hasRole('training_admin')
            || $user->hasRole('training_viewer')
            || $user->can('training.access');

        if (! $hasAccess) {
            abort(404);
        }

        return $next($request);
    }
}
