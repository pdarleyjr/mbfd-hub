<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureLibraryAccountReady
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');
        if ($user && $user->must_change_password) {
            $destination = $user->employee_profile_id !== null ? '/employee/set-password' : '/admin/set-password';
            if ($request->expectsJson() && ! $request->headers->has('X-Livewire')) {
                return response()->json(['message' => 'Set your account password before continuing.', 'code' => 'password_change_required', 'redirect' => $destination], 403);
            }

            return redirect($destination);
        }

        return $next($request);
    }
}
