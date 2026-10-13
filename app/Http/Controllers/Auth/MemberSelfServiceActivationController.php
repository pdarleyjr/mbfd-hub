<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\SafeNewPassword;
use App\Services\Identity\CanonicalLoginDestination;
use App\Services\Identity\CanonicalSessionIssuer;
use App\Services\Identity\FederationLoginAttempt;
use App\Services\Identity\MemberSelfServiceActivation;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class MemberSelfServiceActivationController extends Controller
{
    private const FAILURE = 'We could not activate an account with that information. Check your Employee ID, City email, and the shift and unit you bid, then try again or use email activation.';

    /** Answers a member should not have to re-enter after a refusal. */
    private const REMEMBERED = ['employee_id', 'email', 'shift', 'unit'];

    private const THROTTLED = 'Too many attempts. Wait a few minutes before trying again, or contact MBFD Hub support.';

    private const EMAIL_STATUS = 'If your account is ready for activation, we sent a setup link to the City email on file. It expires in 30 minutes, so check your inbox and Junk folder now.';

    public function show(Request $request, MemberSelfServiceActivation $activation): Response
    {
        if ($request->user('web') instanceof User) {
            return redirect('/');
        }
        if (! $activation->enabled()) {
            return $activation->emailRequestEnabled()
                ? redirect()->route('member-activation.email', $request->only('login_attempt'))
                : redirect('/login');
        }

        return $this->page('auth.member-activation', $request, $activation);
    }

    public function store(
        Request $request,
        MemberSelfServiceActivation $activation,
        CanonicalSessionIssuer $sessionIssuer,
    ): Response {
        abort_unless($activation->enabled(), 404);
        if ($request->user('web') instanceof User) {
            return redirect('/');
        }
        if (! $request->query->has('login_attempt') && is_string($request->input('login_attempt'))) {
            $request->query->set('login_attempt', $request->input('login_attempt'));
        }
        $validator = Validator::make($request->all(), [
            'employee_id' => ['required', 'string', 'max:64'],
            'email' => ['required', 'string', 'max:254'],
            'shift' => ['required', 'string', 'max:32'],
            'unit' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string', 'confirmed', Password::default(), new SafeNewPassword],
        ], [], [
            'employee_id' => 'Employee ID',
            'email' => 'City email',
            'shift' => 'shift',
            'unit' => 'unit',
        ]);
        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput($request->only(self::REMEMBERED));
        }
        $data = $validator->validated();

        $employeeId = trim($data['employee_id']);
        $keys = $this->throttleKeys('claim', $employeeId, (string) $request->ip());
        if (RateLimiter::tooManyAttempts($keys['identity'], 5) || RateLimiter::tooManyAttempts($keys['source'], 20)) {
            return $this->refused($request, 'rate_limited', $employeeId, self::THROTTLED);
        }
        RateLimiter::hit($keys['identity'], 900);
        RateLimiter::hit($keys['source'], 3600);

        // Hash before any lookup so every refusal costs the same as a success.
        $passwordHash = Hash::make($data['password']);
        try {
            $user = $employeeId === ''
                ? null
                : $activation->claim($employeeId, $data['email'], $data['shift'], $data['unit'], $passwordHash, CarbonImmutable::now());
        } catch (Throwable $exception) {
            // Contain failures here so error reporting never receives this form body.
            Log::error('member_self_service_activation_failed', ['exception_class' => $exception::class]);
            $user = null;
        }
        if (! $user instanceof User) {
            return $this->refused($request, 'not_activated', $employeeId, self::FAILURE);
        }

        RateLimiter::clear($keys['identity']);
        try {
            $registeredId = $sessionIssuer->issue($request, $user);
        } catch (Throwable $exception) {
            $sessionIssuer->reject($request);
            Log::error('member_self_service_activation_session_failed', [
                'user_id' => $user->id,
                'exception_class' => $exception::class,
            ]);

            // The account is active; the member signs in with the password just chosen.
            return redirect('/login')->with('status', 'Your account is active. Sign in with your Employee ID and new password.');
        }
        Log::info('member_self_service_activation_succeeded', [
            'user_id' => $user->id,
            'employee_profile_id' => $user->employee_profile_id,
            'authentication_session_id' => $registeredId,
        ]);

        $attempts = app(FederationLoginAttempt::class);

        return $attempts->requested($request) && $attempts->current($request) !== null
            ? $attempts->complete($request)
            : redirect(app(CanonicalLoginDestination::class)->resolve($user, $request->session()->pull('url.intended')));
    }

    public function email(Request $request, MemberSelfServiceActivation $activation): Response
    {
        if ($request->user('web') instanceof User) {
            return redirect('/');
        }
        if (! $activation->emailRequestEnabled()) {
            return redirect($activation->enabled() ? route('member-activation.show') : '/login');
        }

        return $this->page('auth.member-activation-email', $request, $activation);
    }

    public function requestEmail(Request $request, MemberSelfServiceActivation $activation): RedirectResponse
    {
        abort_unless($activation->emailRequestEnabled(), 404);
        $data = $request->validate(['employee_id' => ['required', 'string', 'max:64']], [], ['employee_id' => 'Employee ID']);
        $employeeId = trim($data['employee_id']);
        $keys = $this->throttleKeys('email', $employeeId, (string) $request->ip());
        if (RateLimiter::tooManyAttempts($keys['identity'], 3) || RateLimiter::tooManyAttempts($keys['source'], 10)) {
            return back()->withErrors(['employee_id' => self::THROTTLED]);
        }
        RateLimiter::hit($keys['identity'], 3600);
        RateLimiter::hit($keys['source'], 3600);

        try {
            if ($employeeId !== '') {
                $activation->requestEmailInvitation($employeeId);
            }
        } catch (Throwable $exception) {
            Log::error('member_email_activation_request_failed', ['exception_class' => $exception::class]);
        }

        return redirect()->route('member-activation.email', $request->only('login_attempt'))->with('status', self::EMAIL_STATUS);
    }

    private function page(string $view, Request $request, MemberSelfServiceActivation $activation): Response
    {
        $loginAttempt = $request->query('login_attempt');

        return response()->view($view, [
            'loginAttempt' => is_string($loginAttempt) && preg_match('/\A[a-f0-9]{64}\z/', $loginAttempt) === 1 ? $loginAttempt : null,
            'bidChoices' => $view === 'auth.member-activation' ? $activation->bidChoices() : null,
            'claimEnabled' => $activation->enabled(),
            'emailEnabled' => $activation->emailRequestEnabled(),
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    private function refused(Request $request, string $reason, string $employeeId, string $message): RedirectResponse
    {
        Log::notice('member_self_service_activation_denied', [
            'reason' => $reason,
            'employee_id_fingerprint' => hash_hmac('sha256', $employeeId, (string) config('app.key')),
            'source_fingerprint' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
        ]);

        return back()->withErrors(['activation' => $message])->withInput($request->only(self::REMEMBERED));
    }

    /** @return array{identity:string,source:string} */
    private function throttleKeys(string $purpose, string $employeeId, string $ip): array
    {
        $key = (string) config('app.key');

        return [
            'identity' => "member-activation-{$purpose}:id:".hash_hmac('sha256', strtolower($employeeId), $key),
            'source' => "member-activation-{$purpose}:ip:".hash_hmac('sha256', $ip, $key),
        ];
    }
}
