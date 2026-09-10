<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Rules\SafeNewPassword;
use App\Services\Communications\CloudflareEmailDispatcher;
use App\Services\Identity\AccountSecurityService;
use App\Services\Identity\CanonicalUserProvisioner;
use App\Services\Identity\CityEmailVerificationService;
use App\Services\Identity\IdentityProviderService;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

final class EmployeePasswordResetController extends Controller
{
    private const GENERIC_STATUS = 'If the Employee ID has an active linked account and an authoritative recovery address, account recovery instructions will be sent.';

    public function requestForm(): View
    {
        return view('auth.employee-forgot-password');
    }

    public function requestLink(
        Request $request,
        CloudflareEmailDispatcher $dispatcher,
        CityEmailVerificationService $emails,
        IdentityProviderService $identities,
        CanonicalUserProvisioner $provisioner,
    ): RedirectResponse {
        $validated = $request->validate(['employee_id' => ['required', 'string', 'max:64']]);
        $employeeId = trim((string) $validated['employee_id']);
        $key = 'employee-password-reset:'.hash_hmac('sha256', $employeeId.'|'.$request->ip(), (string) config('app.key'));
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return back()->with('status', self::GENERIC_STATUS);
        }
        RateLimiter::hit($key, 900);

        /** @var Employee|null $employee */
        $employee = Employee::query()->where('employee_id', $employeeId)->first();
        $user = $employee?->user;
        if (! $user instanceof User
            && $employee instanceof Employee
            && config('identity.credential_authority') === 'authentik') {
            try {
                $user = $provisioner->create(
                    (int) $employee->getKey(),
                    'MISSING_OR_UNSUPPORTED',
                    now(),
                )['user'];
            } catch (Throwable) {
                $user = null;
            }
        }
        if ($user instanceof User) {
            try {
                if (config('identity.credential_authority') === 'authentik'
                    && $identities->enabledFor($user)) {
                    $recipient = $emails->connectedEmail($user);
                    if ($recipient === null || ! $user->isUpstreamIdentityEnabled()) {
                        return back()->with('status', self::GENERIC_STATUS);
                    }
                    $url = $identities->recoveryLink($user);
                    $dispatcher->send(
                        to: [$recipient],
                        subject: 'MBFD Identity account recovery',
                        text: "Account recovery was requested for your MBFD Identity account.\n\nContinue securely: {$url}\n\nThis one-time link expires shortly. If you did not request it, ignore this message and contact an administrator.",
                        html: null,
                        sourceType: 'identity_recovery',
                        sourceId: (string) $user->getKey(),
                    );

                    return back()->with('status', self::GENERIC_STATUS);
                }
                $challenge = DB::transaction(function () use ($user, $emails): ?array {
                    $current = User::query()->lockForUpdate()->find($user->id);
                    if (! $current instanceof User || ! $current->isAuthenticationAllowed()
                        || $current->employee_profile_id !== $user->employee_profile_id || $current->employee_id !== $user->employee_id) {
                        return null;
                    }
                    $recipient = $emails->connectedEmail($current);
                    if ($recipient === null) {
                        return null;
                    }

                    return ['recipient' => $recipient, 'token' => $this->passwordBroker()->createToken($current)];
                });
                if ($challenge === null) {
                    return back()->with('status', self::GENERIC_STATUS);
                }
                $url = route('password.reset.form', ['token' => $challenge['token'], 'employee_id' => $employeeId]);
                $dispatcher->send(
                    to: [$challenge['recipient']],
                    subject: 'MBFD Hub password reset',
                    text: "A password reset was requested for your MBFD Hub account.\n\nReset your password: {$url}\n\nIf you did not request this, ignore this message.",
                    html: null,
                    sourceType: 'password_reset',
                    sourceId: (string) $user->getKey(),
                );
            } catch (Throwable $exception) {
                Log::warning('employee_password_reset_delivery_failed', [
                    'user_id' => $user->getKey(),
                    'exception_class' => $exception::class,
                ]);
            }
        }

        return back()->with('status', self::GENERIC_STATUS);
    }

    public function resetForm(string $token, Request $request): View
    {
        return view('auth.employee-reset-password', [
            'token' => $token,
            'employeeId' => (string) $request->query('employee_id', ''),
        ]);
    }

    public function reset(Request $request, AccountSecurityService $security): RedirectResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'string', 'max:64'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::defaults(), new SafeNewPassword],
        ]);
        $employee = Employee::query()->where('employee_id', trim($validated['employee_id']))->first();
        $user = $employee?->user;
        $reset = $user instanceof User && DB::transaction(function () use ($user, $validated, $security): bool {
            $current = User::query()->lockForUpdate()->find($user->id);
            if (! $current instanceof User || ! $current->isAuthenticationAllowed()
                || $current->employee_profile_id !== $user->employee_profile_id || $current->employee_id !== $user->employee_id
                || ! $this->passwordBroker()->tokenExists($current, $validated['token'])) {
                return false;
            }

            if ($current->must_change_password && Hash::check($validated['password'], $current->getAuthPassword())) {
                throw ValidationException::withMessages(['password' => 'Choose a password different from your temporary password.']);
            }

            $security->changePassword($current, Hash::make($validated['password']), now());
            $this->passwordBroker()->deleteToken($current);

            return true;
        });
        if (! $reset) {
            return back()->withErrors(['employee_id' => 'This password reset link is invalid or expired.']);
        }

        return redirect()->route('login')->with('status', 'Your password has been reset.');
    }

    private function passwordBroker(): PasswordBroker
    {
        $broker = Password::broker();
        if (! $broker instanceof PasswordBroker) {
            throw new \LogicException('The configured password broker does not support token persistence.');
        }

        return $broker;
    }
}
