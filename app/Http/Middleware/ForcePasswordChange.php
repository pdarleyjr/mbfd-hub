<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Identity\CanonicalLoginDestination;
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

        if ($user instanceof User && $user->employee_profile_id !== null && $user->must_change_password) {
            if ($request->routeIs('logout', 'filament.*.auth.logout', 'filament.*.pages.set-password')
                || $request->is('manifest.json', 'admin/service-worker.js', 'admin-pwa/service-worker.js', 'admin-pwa/manifest.webmanifest')) {
                return $next($request);
            }

            // The outer Livewire update has no trusted page identity. Livewire
            // verifies each snapshot, then runs this gate through the panel's
            // persistent middleware on that component's actual page route.
            if ($request->routeIs('*livewire.update')) {
                return $next($request);
            }

            $handoff = app(CanonicalLoginDestination::class)->federation($request->getRequestUri());
            if ($request->isMethod('GET') && $handoff !== null
                && ! $request->session()->has(CanonicalLoginDestination::PASSWORD_RETURN_KEY)) {
                $request->session()->put(CanonicalLoginDestination::PASSWORD_RETURN_KEY, $handoff);
            }

            return redirect('/employee/set-password')
                ->with('warning', 'You must change your password before continuing.');
        }

        // Preserve the existing recovery path for unlinked legacy administrators.
        if ($user && $user->must_change_password && $request->is('admin/*')) {
            // Allow access to the My Profile page, login, logout, Livewire requests, and assets
            if (! $request->routeIs('filament.admin.pages.my-profile') &&
                ! $request->is('admin/my-profile') &&
                ! $request->is('admin/login') &&
                ! $request->is('admin/logout') &&
                ! $request->routeIs('filament.admin.auth.login') &&
                ! $request->routeIs('filament.admin.auth.logout') &&
                ! $request->is('livewire/*') &&
                ! $request->is('*/livewire/*') &&
                ! $request->is('*/filament/assets/*') &&
                ! $request->routeIs('logout')) {
                return redirect()->route('filament.admin.pages.my-profile')
                    ->with('warning', 'You must change your password before continuing.');
            }
        }

        return $next($request);
    }
}
