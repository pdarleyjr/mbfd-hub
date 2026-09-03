<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Identity\CanonicalActivationIntent;
use App\Services\Identity\CanonicalSessionPolicy;
use App\Services\Identity\CanonicalUserProvisioner;
use App\Services\Identity\DualCredentialIdentityClaim;
use App\Services\Identity\SessionRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Throwable;

final class FirstLoginCanonicalizationController extends Controller
{
    private const FAILURE_MESSAGE = 'The provided credentials are invalid.';

    public function create(Request $request, CanonicalActivationIntent $intents): View|RedirectResponse
    {
        $nonce = $intents->present($request->session(), CarbonImmutable::now());
        if ($nonce === null) {
            return redirect('/login')->withErrors(['employee_id' => self::FAILURE_MESSAGE]);
        }

        return view('auth.activate-account', ['nonce' => $nonce]);
    }

    public function store(
        Request $request,
        CanonicalActivationIntent $intents,
        CanonicalUserProvisioner $provisioner,
        DualCredentialIdentityClaim $claims,
        CanonicalSessionPolicy $sessionPolicy,
        SessionRegistry $sessions,
    ): RedirectResponse {
        $input = $request->validate([
            'nonce' => ['required', 'string', 'size:64'],
            'path' => ['required', 'string', 'in:existing_user,no_existing_user'],
            'legacy_email' => ['nullable', 'required_if:path,existing_user', 'string', 'email:rfc', 'max:255'],
            'legacy_password' => ['nullable', 'required_if:path,existing_user', 'string', 'max:4096'],
            'no_legacy_account_assertion' => ['nullable', 'accepted_if:path,no_existing_user'],
        ]);
        $at = CarbonImmutable::now();
        $employeeProfileId = $intents->consumeNonce($request->session(), $input['nonce'], $at);
        if ($employeeProfileId === null) {
            $intents->invalidate($request->session());

            return redirect('/login')->withErrors(['employee_id' => self::FAILURE_MESSAGE]);
        }

        $legacyEmail = strtolower(trim((string) ($input['legacy_email'] ?? '')));
        $key = $this->throttleKey($employeeProfileId, $legacyEmail, (string) $request->ip());
        $maxAttempts = max(1, (int) config('security.identity_recovery.max_attempts', 3));
        $decaySeconds = max(1, (int) config('security.identity_recovery.decay_seconds', 900));
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return $this->denied($key, $decaySeconds, 'rate_limited');
        }

        try {
            if ($input['path'] === 'existing_user') {
                $user = $claims->claim(
                    $employeeProfileId,
                    $legacyEmail,
                    (string) $input['legacy_password'],
                    $at,
                );
            } else {
                $user = $provisioner->create(
                    $employeeProfileId,
                    'LEGACY_HUMAN_BCRYPT_UNCHANGED',
                    $at,
                )['user'];
            }
        } catch (Throwable $exception) {
            Log::notice('canonical_first_login_transition_blocked', [
                'employee_profile_id' => $employeeProfileId,
                'path' => $input['path'],
                'exception_class' => $exception::class,
            ]);

            return $this->denied($key, $decaySeconds, 'collision_or_transition_denied');
        }

        if (! $user instanceof User || ! $user->isAuthenticationAllowed()) {
            return $this->denied($key, $decaySeconds, 'credential_or_eligibility_denied');
        }

        RateLimiter::clear($key);
        $intents->invalidate($request->session());
        Auth::guard('web')->login($user, false);
        $request->session()->regenerate();
        $policy = $sessionPolicy->resolve($request, $at);

        try {
            $registered = $sessions->register(
                $user,
                $request->session()->getId(),
                $policy['context_class'],
                $at,
                $policy['idle_expires_at'],
                $policy['absolute_expires_at'],
            );
        } catch (Throwable $exception) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            Log::error('canonical_first_login_session_registration_failed', [
                'user_id' => $user->id,
                'exception_class' => $exception::class,
            ]);

            return redirect('/login')->withErrors(['employee_id' => self::FAILURE_MESSAGE]);
        }

        $request->session()->put('auth.canonical_session_id', $registered->id);
        $request->session()->put(
            (string) config('security.recent_authentication.session_key'),
            $at->getTimestamp(),
        );

        return redirect()->intended('/');
    }

    private function denied(string $key, int $decaySeconds, string $reason): RedirectResponse
    {
        RateLimiter::hit($key, $decaySeconds);
        Log::notice('canonical_first_login_transition_denied', [
            'reason' => $reason,
            'claim_fingerprint' => substr($key, strlen('canonical-first-login:')),
        ]);

        return redirect('/activate-account')->withErrors(['legacy_email' => self::FAILURE_MESSAGE]);
    }

    private function throttleKey(int $employeeProfileId, string $legacyEmail, string $ip): string
    {
        return 'canonical-first-login:'.hash_hmac(
            'sha256',
            $employeeProfileId.'|'.$legacyEmail.'|'.$ip,
            (string) config('app.key'),
        );
    }
}
