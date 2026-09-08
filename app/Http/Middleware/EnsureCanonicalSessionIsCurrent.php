<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AuthenticationSession;
use App\Models\User;
use App\Services\Identity\CanonicalLoginDestination;
use App\Services\Identity\FederationLoginAttempt;
use App\Services\Identity\SessionRegistry;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureCanonicalSessionIsCurrent
{
    public function __construct(private SessionRegistry $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $registryId = $request->hasSession()
            ? $request->session()->get('auth.canonical_session_id')
            : null;

        // D03 has not converted legacy panel sessions yet. Only sessions
        // explicitly issued by D01 carry this marker and are enforced here.
        if (! is_string($registryId) || $registryId === '') {
            return $next($request);
        }

        $authenticated = $request->user('web');
        $user = $authenticated instanceof User
            ? User::query()->find($authenticated->id)
            : null;
        $registered = AuthenticationSession::query()->find($registryId);
        if ($user instanceof User
            && $registered instanceof AuthenticationSession
            && $this->sessions->isCurrent($user, $registered, CarbonImmutable::now())) {
            return $next($request);
        }

        if ($user instanceof User && $registered instanceof AuthenticationSession) {
            $this->sessions->revoke($user, $registryId, 'canonical session no longer current', CarbonImmutable::now());
        }
        // Retain only this navigation's allowlisted federation GET. Invalidation
        // still discards old intended URLs, form data, and authentication state.
        $federation = $request->isMethod('GET') && ! $request->expectsJson() && ! $request->headers->has('X-Livewire')
            ? app(CanonicalLoginDestination::class)->federation($request->getRequestUri())
            : null;
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Livewire sends X-Livewire without Accept: application/json. A normal
        // redirect otherwise gets fetched as login HTML inside its update request.
        if ($request->headers->has('X-Livewire')) {
            return response()->json([
                'message' => 'Your session has ended. Please sign in again.',
                'code' => 'auth_session_expired',
            ], 401, ['Cache-Control' => 'no-store, private']);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $attempts = app(FederationLoginAttempt::class);
        if ($federation !== null) {
            return $attempts->begin($request, $federation);
        }
        if ($request->is('login') && $attempts->requested($request)) {
            return $attempts->current($request) !== null
                ? redirect($attempts->loginUrl($request, expired: true)) : $attempts->unavailable();
        }

        return redirect('/login');
    }
}
