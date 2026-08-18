<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmployeeAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth('employee')->check()) {
            if ($request->expectsJson()) {
                abort(401);
            }
            $request->session()->put('employee.intended_path', '/'.$request->path().($request->getQueryString() ? '?'.$request->getQueryString() : ''));

            return redirect()->guest('/employee/login');
        }

        return $next($request);
    }
}
