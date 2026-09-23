<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Exceptions\MemberBootstrapStateChanged;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\SafeNewPassword;
use App\Services\Identity\AccountSecurityService;
use App\Services\Identity\CanonicalLoginDestination;
use App\Services\Identity\CanonicalSessionIssuer;
use App\Services\Identity\MemberBootstrapSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
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
        ]);
    }

    public function store(
        Request $request,
        MemberBootstrapSession $sessions,
        AccountSecurityService $security,
        CanonicalSessionIssuer $sessionIssuer,
    ): RedirectResponse {
        $user = $sessions->current($request);
        $context = $sessions->context($request);
        if (! $user instanceof User || $context === null) {
            return redirect('/login');
        }
        $validated = $request->validate([
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::default(),
                new SafeNewPassword,
            ],
        ]);

        try {
            $user = $security->completeMemberOnboarding(
                $context['invitation_id'],
                $user->id,
                $context['employee_profile_id'],
                $context['security_version'],
                $context['binding'],
                Hash::make($validated['password']),
                CarbonImmutable::now(),
            );
        } catch (MemberBootstrapStateChanged|InvalidArgumentException) {
            $sessions->cancel($request);

            return redirect('/login');
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
}
