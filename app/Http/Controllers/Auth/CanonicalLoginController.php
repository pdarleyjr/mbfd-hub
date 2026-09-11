<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Identity\AuthentikOidcClient;
use App\Services\Identity\CanonicalLoginDestination;
use App\Services\Identity\CanonicalSessionIssuer;
use App\Services\Identity\CanonicalUserResolver;
use App\Services\Identity\FederationLoginAttempt;
use App\Services\Identity\MemberBootstrapCredential;
use App\Services\Identity\MemberBootstrapSession;
use App\Services\Identity\SessionRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class CanonicalLoginController extends Controller
{
    private const FAILURE_MESSAGE = 'The provided credentials are invalid.';

    public function create(
        Request $request,
        FederationLoginAttempt $attempts,
        MemberBootstrapCredential $bootstrapCredential,
        MemberBootstrapSession $bootstrapSessions,
    ): View|Response {
        if ($attempts->requested($request) && $attempts->current($request) === null) {
            return $attempts->unavailable();
        }
        if ($request->user('web') instanceof User) {
            return $attempts->requested($request) ? $attempts->complete($request) : redirect('/');
        }
        if ($bootstrapSessions->current($request) instanceof User) {
            return redirect()->route('member-onboarding.show');
        }

        if (! (bool) config('identity.local_login_enabled') && ! $bootstrapCredential->available()) {
            return redirect()->route('identity.redirect', $request->only('login_attempt'));
        }

        return view('auth.canonical-login', [
            'loginAction' => $attempts->requested($request) ? $attempts->loginUrl($request) : route('login.store'),
            'applicationLabel' => $attempts->requested($request) ? $attempts->applicationLabel($request) : null,
            'identityLoginUrl' => config('identity.credential_authority') === 'authentik'
                && in_array(config('identity.mode'), ['hybrid', 'authentik'], true)
                ? route('identity.redirect', $request->only('login_attempt'))
                : null,
        ]);
    }

    public function store(
        Request $request,
        CanonicalUserResolver $users,
        CanonicalSessionIssuer $sessionIssuer,
        MemberBootstrapCredential $bootstrapCredential,
        MemberBootstrapSession $bootstrapSessions,
    ): Response {
        abort_unless((bool) config('identity.local_login_enabled') || $bootstrapCredential->available(), 404);
        $attempts = app(FederationLoginAttempt::class);
        if ($attempts->requested($request) && $attempts->current($request) === null) {
            return $attempts->unavailable();
        }
        if ($request->user('web') instanceof User) {
            return $attempts->requested($request) ? $attempts->complete($request) : redirect('/');
        }
        $validator = Validator::make($request->all(), [
            'employee_id' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:4096'],
        ]);
        if ($attempts->requested($request) && $validator->fails()) {
            return redirect($attempts->loginUrl($request))->withErrors($validator);
        }
        $credentials = $validator->validate();
        $employeeId = trim($credentials['employee_id']);
        $throttleKey = $this->throttleKey($employeeId, (string) $request->ip());
        $maxAttempts = max(1, (int) config('security.canonical_login.max_attempts', 5));
        $decaySeconds = max(1, (int) config('security.canonical_login.decay_seconds', 60));
        $bootstrapThrottleKey = $this->bootstrapThrottleKey((string) $request->ip());
        $bootstrapMaxAttempts = max(1, (int) config('security.member_bootstrap.global_max_attempts', 30));
        $bootstrapAvailable = $bootstrapCredential->available();

        if ($employeeId === ''
            || RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            return $this->denied($request, $employeeId, 'rate_limited');
        }

        $user = $users->byEmployeeId($employeeId);
        $passwordMatches = Hash::check(
            $credentials['password'],
            $user?->getAuthPassword() ?? (string) config('identity.canonical_login_dummy_password_hash'),
        );
        $bootstrapMatches = $bootstrapCredential->matches($credentials['password']);
        $bootstrapCandidate = ! $attempts->requested($request)
            && $user instanceof User
            && $user->isBootstrapOnboardingPending()
            && $bootstrapAvailable;

        if ($bootstrapCandidate && RateLimiter::tooManyAttempts($bootstrapThrottleKey, $bootstrapMaxAttempts)) {
            return $this->denied($request, $employeeId, 'rate_limited');
        }

        if ($bootstrapCandidate && $bootstrapMatches) {
            RateLimiter::clear($throttleKey);
            $bootstrapSessions->begin($request, $user);
            Log::info('member_bootstrap_authentication_succeeded', [
                'user_id' => $user->id,
                'employee_profile_id' => $user->employee_profile_id,
                'authentication_method' => 'restricted_member_bootstrap',
            ]);

            return redirect()->route('member-onboarding.show');
        }
        if ($bootstrapCandidate) {
            RateLimiter::hit(
                $bootstrapThrottleKey,
                max(1, (int) config('security.member_bootstrap.decay_seconds', $decaySeconds)),
            );
        }

        $denialReason = $this->denialReason($user, $passwordMatches);
        if ($denialReason !== null) {
            RateLimiter::hit($throttleKey, $decaySeconds);

            return $this->denied($request, $employeeId, $denialReason);
        }
        assert($user instanceof User);

        RateLimiter::clear($throttleKey);
        try {
            $registeredId = $sessionIssuer->issue($request, $user);
        } catch (Throwable $exception) {
            $sessionIssuer->reject($request);
            Log::error('canonical_authentication_session_registration_failed', [
                'user_id' => $user->id,
                'exception_class' => $exception::class,
            ]);

            return $this->denied($request, $employeeId, 'session_registration_failed');
        }

        Log::info('canonical_authentication_succeeded', [
            'user_id' => $user->id,
            'employee_profile_id' => $user->employee_profile_id,
            'authentication_session_id' => $registeredId,
            'authentication_method' => 'local',
        ]);

        return $attempts->requested($request) ? $attempts->complete($request)
            : redirect(app(CanonicalLoginDestination::class)->resolve($user, $request->session()->pull('url.intended')));
    }

    public function destroy(Request $request, SessionRegistry $sessions, AuthentikOidcClient $oidc): RedirectResponse
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

        $endSessionEndpoint = $request->session()->pull('auth.authentik_end_session_endpoint');
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $endSessionUrl = is_string($endSessionEndpoint) ? $oidc->endSessionUrl($endSessionEndpoint) : null;

        return $endSessionUrl !== null ? redirect()->away($endSessionUrl) : redirect('/login');
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

        $attempts = app(FederationLoginAttempt::class);

        return ($attempts->requested($request) ? redirect($attempts->loginUrl($request)) : back())
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

    private function bootstrapThrottleKey(string $ip): string
    {
        return 'member-bootstrap-source:'.hash_hmac('sha256', $ip, (string) config('app.key'));
    }
}
