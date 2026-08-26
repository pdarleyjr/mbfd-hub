<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Filament\Pages\SetPasswordPage;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

class ForceFilamentPasswordChange
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $panel = Filament::getCurrentPanel();

        if (! $user || ! $user->must_change_password || ! $panel) {
            return $next($request);
        }

        $panelId = $panel->getId();
        $setPasswordUrl = SetPasswordPage::getUrl(panel: $panelId);
        $setPasswordPath = trim((string) parse_url($setPasswordUrl, PHP_URL_PATH), '/');

        if ($request->routeIs(
            SetPasswordPage::getRouteName($panelId),
            $panel->generateRouteName('auth.logout'),
        ) || $request->path() === $setPasswordPath || trim(Livewire::originalPath(), '/') === $setPasswordPath) {
            return $next($request);
        }

        return redirect()
            ->to($setPasswordUrl)
            ->with('warning', 'You must change your password before continuing.');
    }
}
