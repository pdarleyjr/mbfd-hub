<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class CanonicalSessionIssuer
{
    public function __construct(
        private readonly CanonicalSessionPolicy $policy,
        private readonly SessionRegistry $sessions,
        private readonly CityEmailVerificationService $cityEmail,
    ) {}

    public function issue(Request $request, User $user): string
    {
        if (! $user->isAuthenticationAllowed()) {
            throw new \LogicException('A canonical session cannot be issued for this account.');
        }
        Auth::guard('web')->login($user, false);
        $request->session()->regenerate();
        $issuedAt = CarbonImmutable::now();
        $resolved = $this->policy->resolve($request, $issuedAt);
        $registered = $this->sessions->register(
            $user,
            $request->session()->getId(),
            $resolved['context_class'],
            $issuedAt,
            $resolved['idle_expires_at'],
            $resolved['absolute_expires_at'],
        );
        $request->session()->put('auth.canonical_session_id', $registered->id);
        $request->session()->put(
            \App\Http\Middleware\EnsureCityEmailReview::SESSION_KEY,
            $this->cityEmail->requiresReview($user),
        );
        $request->session()->put(
            (string) config('security.recent_authentication.session_key'),
            $issuedAt->getTimestamp(),
        );
        $user->forceFill(['last_login_at' => $issuedAt])->save();

        return (string) $registered->id;
    }

    public function reject(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
