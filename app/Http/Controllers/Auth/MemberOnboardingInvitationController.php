<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Identity\MemberBootstrapSession;
use App\Services\Identity\MemberOnboardingInvitationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

final class MemberOnboardingInvitationController extends Controller
{
    public function show(): Response
    {
        return response()->view('auth.member-onboarding-invitation')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'no-store, private');
    }

    public function redeem(
        Request $request,
        MemberOnboardingInvitationService $invitations,
        MemberBootstrapSession $sessions,
    ): RedirectResponse {
        $data = $request->validate(['token' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/']]);
        $throttleKey = 'member-onboarding-invitation:'.hash_hmac('sha256', hash('sha256', $data['token']).'|'.$request->ip(), (string) config('app.key'));
        if (RateLimiter::tooManyAttempts($throttleKey, 6)) {
            return redirect('/login')->withErrors(['employee_id' => 'This setup link is unavailable. Contact MBFD Hub support for a new invitation.']);
        }
        RateLimiter::hit($throttleKey, 300);
        $binding = Str::random(48);
        $grant = $invitations->redeem($data['token'], $binding, CarbonImmutable::now());
        if ($grant === null) {
            return redirect('/login')->withErrors(['employee_id' => 'This setup link is invalid, expired, or already used. Contact MBFD Hub support for a new invitation.']);
        }
        $sessions->begin($request, $grant['user'], $grant['invitation_id'], $binding);

        return redirect()->route('member-onboarding.show');
    }
}
