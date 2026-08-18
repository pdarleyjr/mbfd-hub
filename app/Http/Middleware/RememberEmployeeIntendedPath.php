<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RememberEmployeeIntendedPath
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (
            $request->isMethod('GET')
            && $request->acceptsHtml()
            && ! auth('employee')->check()
            && $request->is('employee/*')
            && ! $request->is('employee/login')
        ) {
            $path = '/'.$request->path();
            if ($request->getQueryString()) {
                $path .= '?'.$request->getQueryString();
            }
            $request->session()->put('employee.intended_path', $path);
        }

        return $next($request);
    }
}
