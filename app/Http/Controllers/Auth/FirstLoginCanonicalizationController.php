<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Identity\CanonicalActivationIntent;
use App\Services\Identity\CanonicalSessionPolicy;
use App\Services\Identity\CanonicalUserProvisioner;
use App\Services\Identity\DualCredentialIdentityClaim;
use App\Services\Identity\FederationLoginAttempt;
use App\Services\Identity\SessionRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class FirstLoginCanonicalizationController extends Controller
{
    private const FAILURE_MESSAGE = 'The provided credentials are invalid.';

    public function create(Request $request, CanonicalActivationIntent $intents): View|Response
    {
        $attempts = app(FederationLoginAttempt::class);
        if ($attempts->requested($request) && $attempts->current($request) === null) {
            return $attempts->unavailable();
        }
        $nonce = $intents->present($request->session(), CarbonImmutable::now());
        if ($nonce === null) {
            return redirect($attempts->requested($request) ? $attempts->loginUrl($request) : '/login')
                ->withErrors(['employee_id' => self::FAILURE_MESSAGE]);
        }

        return view('auth.activate-account', [
            'nonce' => $nonce,
            'loginAttempt' => $attempts->requested($request) ? $request->query('login_attempt') : null,
        ]);
    }

    public function store(
        Request $request,
        CanonicalActivationIntent $intents,
        CanonicalUserProvisioner $provisioner,
        DualCredentialIdentityClaim $claims,
        CanonicalSessionPolicy $sessionPolicy,
        SessionRegistry $sessions,
    ): Response {
        $attempts = app(FederationLoginAttempt::class);
        if ($attempts->requested($request) && $attempts->current($request) === null) {
            return $attempts->unavailable();
        }
        $validator = Validator::make($request->all(), [
            'nonce' => ['required', 'string', 'size:64'],
            'path' => ['required', 'string', 'in:existing_user,no_existing_user'],
            'legacy_email' => ['nullable', 'required_if:path,existing_user', 'string', 'email:rfc', 'max:255'],
            'legacy_password' => ['nullable', 'required_if:path,existing_user', 'string', 'max:4096'],
            'no_legacy_account_assertion' => ['nullable', 'accepted_if:path,no_existing_user'],
        ]);
        if ($attempts->requested($request) && $validator->fails()) {
            return redirect('/activate-account?'.http_build_query(['login_attempt' => $request->query('login_attempt')]))
                ->withErrors($validator);
        }
        $input = $validator->validate();
        $at = CarbonImmutable::now();
        $employeeProfileId = $intents->consumeNonce($request->session(), $input['nonce'], $at);
        if ($employeeProfileId === null) {
            $intents->invalidate($request->session());

            return redirect($attempts->requested($request) ? $attempts->loginUrl($request) : '/login')
                ->withErrors(['employee_id' => self::FAILURE_MESSAGE]);
        }

        $legacyEmail = strtolower(trim((string) ($input['legacy_email'] ?? '')));
        $key = $this->throttleKey($employeeProfileId, $legacyEmail, (string) $request->ip());
        $maxAttempts = max(1, (int) config('security.identity_recovery.max_attempts', 3));
        $decaySeconds = max(1, (int) config('security.identity_recovery.decay_seconds', 900));
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return $this->denied($request, $key, $decaySeconds, 'rate_limited');
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

            return $this->denied($request, $key, $decaySeconds, 'collision_or_transition_denied');
        }

        if (! $user instanceof User || ! $user->isAuthenticationAllowed()) {
            return $this->denied($request, $key, $decaySeconds, 'credential_or_eligibility_denied');
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

            return redirect($attempts->requested($request) ? $attempts->loginUrl($request) : '/login')
                ->withErrors(['employee_id' => self::FAILURE_MESSAGE]);
        }

        $request->session()->put('auth.canonical_session_id', $registered->id);
        $request->session()->put(
            \App\Http\Middleware\EnsureCityEmailReview::SESSION_KEY,
            app(\App\Services\Identity\CityEmailVerificationService::class)->requiresReview($user),
        );
        $request->session()->put(
            (string) config('security.recent_authentication.session_key'),
            $at->getTimestamp(),
        );

        return $attempts->requested($request) ? $attempts->complete($request)
            : redirect(app(\App\Services\Identity\CanonicalLoginDestination::class)->resolve($user, $request->session()->pull('url.intended')));
    }

    private function denied(Request $request, string $key, int $decaySeconds, string $reason): RedirectResponse
    {
        RateLimiter::hit($key, $decaySeconds);
        Log::notice('canonical_first_login_transition_denied', [
            'reason' => $reason,
            'claim_fingerprint' => substr($key, strlen('canonical-first-login:')),
        ]);

        return redirect('/activate-account'.(app(FederationLoginAttempt::class)->requested($request)
            ? '?'.http_build_query(['login_attempt' => $request->query('login_attempt')]) : ''))
            ->withErrors(['legacy_email' => self::FAILURE_MESSAGE]);
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
