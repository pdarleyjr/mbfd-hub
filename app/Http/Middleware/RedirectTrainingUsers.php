<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectTrainingUsers
{
    /**
     * Training-only users accessing /admin should be redirected to /training.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // If user has admin or super_admin role, allow access
        if ($user->hasRole('super_admin') || $user->hasRole('admin')) {
            return $next($request);
        }

        // Training-only users → redirect to /training
        if ($user->hasRole('training_admin') || $user->hasRole('training_viewer')) {
            return redirect('/training');
        }

        return $next($request);
    }
}
