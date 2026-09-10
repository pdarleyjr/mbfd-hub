<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

final class MemberBootstrapSession
{
    public const KEY = 'auth.member_bootstrap';

    public function __construct(private readonly MemberBootstrapCredential $credential) {}

    public function begin(Request $request, User $user): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $now = CarbonImmutable::now();
        $ttl = max(60, (int) config('identity.member_bootstrap.session_ttl_seconds', 900));
        $request->session()->put(self::KEY, [
            'user_id' => $user->getKey(),
            'employee_profile_id' => $user->employee_profile_id,
            'security_version' => $user->security_version,
            'issued_at' => $now->getTimestamp(),
            'expires_at' => $now->addSeconds($ttl)->getTimestamp(),
            'binding' => Str::random(48),
        ]);
    }

    public function current(Request $request): ?User
    {
        $context = $request->session()->get(self::KEY);
        if (! $this->credential->available() || ! is_array($context)
            || ! is_int($context['user_id'] ?? null)
            || ! is_int($context['employee_profile_id'] ?? null)
            || ! is_int($context['security_version'] ?? null)
            || ! is_int($context['expires_at'] ?? null)
            || ! is_string($context['binding'] ?? null)
            || $context['expires_at'] < CarbonImmutable::now()->getTimestamp()) {
            $this->forget($request);

            return null;
        }

        $user = User::query()->with('employeeProfile')->find($context['user_id']);
        if (! $user instanceof User
            || $user->employee_profile_id !== $context['employee_profile_id']
            || $user->security_version !== $context['security_version']
            || ! $user->isBootstrapOnboardingPending()) {
            $this->forget($request);

            return null;
        }

        return $user;
    }

    /** @return array{user_id:int, employee_profile_id:int, security_version:int, issued_at:int, expires_at:int, binding:string}|null */
    public function context(Request $request): ?array
    {
        return $this->current($request) instanceof User ? $request->session()->get(self::KEY) : null;
    }

    public function cancel(Request $request): void
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    public function prepareCanonicalTransition(Request $request): void
    {
        $request->session()->forget(self::KEY);
        $request->session()->regenerateToken();
    }

    private function forget(Request $request): void
    {
        if ($request->session()->has(self::KEY)) {
            $request->session()->forget(self::KEY);
            $request->session()->regenerate();
            $request->session()->regenerateToken();
        }
    }
}
