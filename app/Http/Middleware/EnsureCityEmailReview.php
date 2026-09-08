<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Identity\CityEmailVerificationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCityEmailReview
{
    public const SESSION_KEY = 'auth.city_email_review_required';

    public const RETURN_KEY = 'auth.city_email_return_to';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');
        if (! $user instanceof User || ! $request->hasSession()
            || ! $request->session()->get(self::SESSION_KEY, false)) {
            return $next($request);
        }

        // Public PWA assets must remain JavaScript/manifests, never an onboarding redirect.
        if ($request->is('manifest.json', 'admin/service-worker.js', 'admin-pwa/service-worker.js', 'admin-pwa/manifest.webmanifest')) {
            return $next($request);
        }

        if (! app(CityEmailVerificationService::class)->requiresReview($user)) {
            $request->session()->forget(self::SESSION_KEY);

            return $next($request);
        }

        if ($request->routeIs('logout', 'filament.*.auth.logout')) {
            return $next($request);
        }

        // A fresh first-login session must complete password setup before review,
        // including entry through the non-panel homepage or daily application.
        if ($user->must_change_password) {
            if ($request->routeIs('filament.*.pages.set-password', 'filament.admin.pages.my-profile')) {
                return $next($request);
            }

            // Livewire validates each snapshot before re-running this persistent
            // middleware with that component's actual page route. Do not trust
            // an unverified originalPath or the first component in a batch here.
            if ($request->routeIs('*livewire.update')) {
                return $next($request);
            }

            return redirect('/employee/set-password');
        }

        if ($request->routeIs('city-email.*')) {
            return $next($request);
        }

        if ($request->isMethod('GET') && ! $request->expectsJson()
            && ! $request->session()->has(self::RETURN_KEY)) {
            $destination = $request->getRequestUri();
            if (self::isSafeReturnPath($destination)) {
                $request->session()->put(self::RETURN_KEY, $destination);
            }
        }

        if ($request->expectsJson() && ! $request->hasHeader('X-Livewire')) {
            return response()->json([
                'message' => 'Confirm your city email before continuing.',
                'code' => 'city_email_review_required',
                'redirect_url' => route('city-email.show'),
            ], 409);
        }

        return redirect()->route('city-email.show');
    }

    public static function isSafeReturnPath(mixed $path): bool
    {
        if (! is_string($path) || ! str_starts_with($path, '/')) {
            return false;
        }

        $decoded = rawurldecode(rawurldecode($path));
        $parts = parse_url($decoded);

        return is_array($parts)
            && ! isset($parts['host'])
            && ! isset($parts['scheme'])
            && ! str_starts_with($decoded, '//')
            && ! str_contains($decoded, '\\')
            && preg_match('/[\x00-\x1F\x7F]/', $decoded) !== 1
            && array_intersect(explode('/', $parts['path'] ?? ''), ['.', '..']) === []
            && ! str_starts_with($parts['path'] ?? '', '/account/city-email');
    }
}
