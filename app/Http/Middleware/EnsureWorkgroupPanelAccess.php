<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkgroupPanelAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $hasAccess = $user->hasRole('super_admin')
            || $user->hasRole('admin')
            || $user->hasRole('workgroup_admin')
            || $user->hasRole('workgroup_facilitator')
            || $user->hasRole('workgroup_member')
            || $user->can('workgroup.access');

        if (! $hasAccess) {
            abort(404);
        }

        return $next($request);
    }
}