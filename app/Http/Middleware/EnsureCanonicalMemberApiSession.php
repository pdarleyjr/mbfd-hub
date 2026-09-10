<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AuthenticationSession;
use App\Models\User;
use App\Services\Identity\SessionRegistry;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureCanonicalMemberApiSession
{
    public function __construct(private SessionRegistry $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $webUser = $request->user('web');
        $registryId = $request->hasSession()
            ? $request->session()->get('auth.canonical_session_id')
            : null;
        $registered = is_string($registryId) && $registryId !== ''
            ? AuthenticationSession::query()->find($registryId)
            : null;

        if (! $user instanceof User
            || ! $webUser instanceof User
            || ! $user->is($webUser)
            || $request->bearerToken() !== null
            || ! $registered instanceof AuthenticationSession
            || ! $this->sessions->isCurrent($user, $registered, CarbonImmutable::now())) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
