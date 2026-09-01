<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Identity\CanonicalSessionPolicy;
use App\Services\Identity\CanonicalUserResolver;
use App\Services\Identity\SessionRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

final class CanonicalLoginController extends Controller
{
    private const FAILURE_MESSAGE = 'The provided credentials are invalid.';

    public function create(): View
    {
        return view('auth.canonical-login');
    }

    public function store(
        Request $request,
        CanonicalUserResolver $users,
        CanonicalSessionPolicy $sessionPolicy,
        SessionRegistry $sessions,
    ): RedirectResponse {
        $credentials = $request->validate([
            'employee_id' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:4096'],
        ]);
        $employeeId = trim($credentials['employee_id']);
        $throttleKey = $this->throttleKey($employeeId, (string) $request->ip());
        $maxAttempts = max(1, (int) config('security.canonical_login.max_attempts', 5));
        $decaySeconds = max(1, (int) config('security.canonical_login.decay_seconds', 60));

        if ($employeeId === '' || RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            return $this->denied($request, $employeeId, 'rate_limited');
        }

        $user = $users->byEmployeeId($employeeId);
        // Generate the same-cost throwaway hash for every attempt so the
        // externally generic response does not gain an obvious lookup timing path.
        $dummyHash = Hash::make(Str::random(48));
        $passwordMatches = Hash::check(
            $credentials['password'],
            $user?->getAuthPassword() ?? $dummyHash,
        );

        $denialReason = $this->denialReason($user, $passwordMatches);
        if ($denialReason !== null) {
            RateLimiter::hit($throttleKey, $decaySeconds);

            return $this->denied($request, $employeeId, $denialReason);
        }
        assert($user instanceof User);

        RateLimiter::clear($throttleKey);
        Auth::guard('web')->login($user, false);
        $request->session()->regenerate();
        $issuedAt = CarbonImmutable::now();
        $policy = $sessionPolicy->resolve($request, $issuedAt);

        try {
            $registered = $sessions->register(
                $user,
                $request->session()->getId(),
                $policy['context_class'],
                $issuedAt,
                $policy['idle_expires_at'],
                $policy['absolute_expires_at'],
            );
        } catch (Throwable $exception) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            Log::error('canonical_authentication_session_registration_failed', [
                'user_id' => $user->id,
                'exception_class' => $exception::class,
            ]);

            return $this->denied($request, $employeeId, 'session_registration_failed');
        }

        $request->session()->put('auth.canonical_session_id', $registered->id);
        $request->session()->put(
            (string) config('security.recent_authentication.session_key'),
            $issuedAt->getTimestamp(),
        );

        Log::info('canonical_authentication_succeeded', [
            'user_id' => $user->id,
            'employee_profile_id' => $user->employee_profile_id,
            'authentication_session_id' => $registered->id,
            'context_class' => $policy['context_class']->value,
        ]);

        return redirect()->intended('/');
    }

    public function destroy(Request $request, SessionRegistry $sessions): RedirectResponse
    {
        $user = $request->user('web');
        $registryId = $request->session()->get('auth.canonical_session_id');
        if ($user instanceof User && is_string($registryId)) {
            $sessions->revoke($user, $registryId, 'logout', CarbonImmutable::now());
            Log::info('canonical_authentication_logout', [
                'user_id' => $user->id,
                'authentication_session_id' => $registryId,
            ]);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    private function denialReason(?User $user, bool $passwordMatches): ?string
    {
        if ($user === null) {
            return 'identity_not_deterministically_linked';
        }
        if (! $user->isAuthenticationAllowed()) {
            return 'account_status_denied';
        }
        if (! $passwordMatches) {
            return 'credential_denied';
        }

        return null;
    }

    private function denied(Request $request, string $employeeId, string $reason): RedirectResponse
    {
        Log::notice('canonical_authentication_denied', [
            'reason' => $reason,
            'employee_id_fingerprint' => hash_hmac('sha256', $employeeId, (string) config('app.key')),
            'source_fingerprint' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
        ]);

        return back()
            ->withErrors(['employee_id' => self::FAILURE_MESSAGE])
            ->withInput($request->only('employee_id'));
    }

    private function throttleKey(string $employeeId, string $ip): string
    {
        return 'canonical-login:'.hash_hmac(
            'sha256',
            $employeeId.'|'.$ip,
            (string) config('app.key'),
        );
    }
}
