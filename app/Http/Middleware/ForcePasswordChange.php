<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ForcePasswordChange
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        
        // Check if user is authenticated and must change password
        if ($user && $user->must_change_password) {
            // Allow access to the My Profile page, login, logout, Livewire requests, and assets
            if (!$request->routeIs('filament.admin.pages.my-profile') && 
                !$request->is('admin/my-profile') &&
                !$request->is('admin/login') &&
                !$request->is('admin/logout') &&
                !$request->routeIs('filament.admin.auth.login') &&
                !$request->routeIs('filament.admin.auth.logout') &&
                !$request->is('*/livewire/*') &&
                !$request->is('*/filament/assets/*') &&
                !$request->routeIs('logout')) {
                return redirect()->route('filament.admin.pages.my-profile')
                    ->with('warning', 'You must change your password before continuing.');
            }
        }
        
        return $next($request);
    }
}
