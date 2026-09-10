<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Exceptions\MemberBootstrapStateChanged;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\NotMemberBootstrapPassword;
use App\Rules\SafeNewPassword;
use App\Services\Identity\AccountSecurityService;
use App\Services\Identity\CanonicalLoginDestination;
use App\Services\Identity\CanonicalSessionIssuer;
use App\Services\Identity\MemberBootstrapCredential;
use App\Services\Identity\MemberBootstrapSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

final class MemberBootstrapOnboardingController extends Controller
{
    public function show(Request $request, MemberBootstrapSession $sessions): View|RedirectResponse
    {
        $user = $sessions->current($request);
        if (! $user instanceof User) {
            return redirect('/login');
        }

        return view('auth.member-bootstrap-onboarding', [
            'user' => $user,
            'cityEmail' => $this->authoritativeCityEmail($user),
        ]);
    }

    public function store(
        Request $request,
        MemberBootstrapSession $sessions,
        MemberBootstrapCredential $credential,
        AccountSecurityService $security,
        CanonicalSessionIssuer $sessionIssuer,
    ): RedirectResponse {
        $user = $sessions->current($request);
        $context = $sessions->context($request);
        if (! $user instanceof User || $context === null) {
            return redirect('/login');
        }
        if (is_string($request->input('city_email'))) {
            $request->merge(['city_email' => strtolower(trim($request->string('city_email')->toString()))]);
        }

        $validated = $request->validate([
            'city_email' => ['required', 'string', 'max:254', 'email:rfc', 'ends_with:@miamibeachfl.gov'],
            'city_email_confirmed' => ['accepted'],
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::default(),
                new SafeNewPassword,
                new NotMemberBootstrapPassword($credential),
            ],
        ]);

        try {
            $user = $security->completeMemberBootstrap(
                $user->id,
                $context['employee_profile_id'],
                $context['security_version'],
                $validated['city_email'],
                Hash::make($validated['password']),
                CarbonImmutable::now(),
            );
        } catch (MemberBootstrapStateChanged) {
            $sessions->cancel($request);

            return redirect('/login');
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'city_email' => 'That City email cannot be used. Check the address or contact an administrator.',
            ]);
        }

        $sessions->prepareCanonicalTransition($request);
        $sessionIssuer->issue($request, $user);

        return redirect(app(CanonicalLoginDestination::class)->resolve($user, null));
    }

    public function cancel(Request $request, MemberBootstrapSession $sessions): RedirectResponse
    {
        $sessions->cancel($request);

        return redirect('/login');
    }

    private function authoritativeCityEmail(User $user): string
    {
        foreach ([$user->employeeProfile?->city_email, $user->email] as $value) {
            $email = is_string($value) ? strtolower(trim($value)) : '';
            if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false
                && str_ends_with($email, '@miamibeachfl.gov')) {
                return $email;
            }
        }

        return '';
    }
}
