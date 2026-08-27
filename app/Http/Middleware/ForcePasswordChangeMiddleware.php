<?php

namespace App\Http\Middleware;

use App\Filament\Employee\Pages\ChangePasswordPage;
use Closure;
use Illuminate\Http\Request;
use Livewire\Livewire;
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

        $changePasswordUrl = ChangePasswordPage::getUrl(panel: 'employee');
        $changePasswordPath = trim((string) parse_url($changePasswordUrl, PHP_URL_PATH), '/');

        // Allow the password page, its Livewire update transport, and logout.
        if ($request->routeIs(
            ChangePasswordPage::getRouteName('employee'),
            'filament.employee.auth.logout',
        ) || $request->path() === $changePasswordPath || trim(Livewire::originalPath(), '/') === $changePasswordPath) {
            return $next($request);
        }

        if ($employee->must_change_password) {
            return redirect()->to($changePasswordUrl);
        }

        return $next($request);
    }
}
