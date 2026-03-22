<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForcePasswordChangeMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Use the 'employee' guard — completely independent from 'web' guard
        $employee = auth('employee')->user();

        if (! $employee) {
            return $next($request);
        }

        // Skip the change-password page itself to avoid redirect loops
        if ($request->routeIs('filament.employee.pages.change-password-page')) {
            return $next($request);
        }

        // Skip logout route
        if ($request->routeIs('filament.employee.auth.logout')) {
            return $next($request);
        }

        if ($employee->must_change_password) {
            return redirect()->route('filament.employee.pages.change-password-page');
        }

        return $next($request);
    }
}
