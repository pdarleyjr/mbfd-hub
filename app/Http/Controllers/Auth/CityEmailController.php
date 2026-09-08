<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCityEmailReview;
use App\Models\User;
use App\Services\Identity\CityEmailVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CityEmailController extends Controller
{
    public function show(Request $request, CityEmailVerificationService $emails): Response
    {
        $user = $this->member($request);

        return response()->view('auth.city-email', [
            'user' => $user,
            'candidate' => $emails->candidate($user),
            'connectedEmail' => $emails->connectedEmail($user),
            'verification' => $emails->status($user),
            'requiresReview' => $emails->requiresReview($user),
        ]);
    }

    public function store(Request $request, CityEmailVerificationService $emails): RedirectResponse
    {
        $user = $this->member($request);
        $this->throttle($request, $user);
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'regex:/\A[^@\s]+@miamibeachfl\.gov\z/i'],
            'current_password' => ['required', 'string', 'max:4096', 'current_password:web'],
            'ownership_confirmed' => ['accepted'],
        ]);

        try {
            $emails->acknowledge($user, $data['email']);
            $verification = $emails->issue($user);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['email' => $exception->getMessage()]);
        }

        $request->session()->forget(EnsureCityEmailReview::SESSION_KEY);

        if ($verification->verified_at !== null) {
            return redirect()->route('city-email.show')->with('status', 'Your city email is already verified and connected.');
        }

        return redirect()->route('city-email.show')->with('status', $verification->delivery_status === 'failed'
            ? 'Your address was saved for review, but the verification email could not be sent. You can continue using the Hub and retry here. Your email is not yet verified.'
            : 'Check your city mailbox for the verification link. You can continue using the Hub while verification is pending.');
    }

    public function resend(Request $request, CityEmailVerificationService $emails): RedirectResponse
    {
        $user = $this->member($request);
        $this->throttle($request, $user);
        $request->validate([
            'current_password' => ['required', 'string', 'max:4096', 'current_password:web'],
            'ownership_confirmed' => ['accepted'],
        ]);
        abort_if($emails->status($user) === null, 409, 'Confirm your city email before requesting another link.');
        try {
            $verification = $emails->issue($user);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['email' => $exception->getMessage()]);
        }

        return redirect()->route('city-email.show')->with('status', $verification->delivery_status === 'failed'
            ? 'The verification email could not be sent. You can continue using the Hub and retry later. Your email is not yet verified.'
            : 'A new verification link was sent. Earlier links no longer work.');
    }

    public function inspect(Request $request, string $token, CityEmailVerificationService $emails): Response
    {
        $verification = $emails->inspectToken($this->member($request), $token);

        return response()->view('auth.city-email-verify', [
            'verification' => $verification,
            'token' => $verification === null ? null : $token,
        ], $verification === null ? 422 : 200);
    }

    public function verify(Request $request, string $token, CityEmailVerificationService $emails): RedirectResponse
    {
        $user = $this->member($request);
        $this->throttle($request, $user);
        $request->validate(['confirm_verification' => ['accepted']]);
        if (! $emails->verify($user, $token)) {
            return redirect()->route('city-email.show')
                ->withErrors(['verification' => 'This verification link is invalid, expired, or no longer belongs to this account. Request a new link.']);
        }

        $request->session()->forget(EnsureCityEmailReview::SESSION_KEY);

        return redirect()->route('city-email.show')->with('status', 'Your city email is verified and connected to your Hub account.');
    }

    public function continueToHub(Request $request, CityEmailVerificationService $emails): RedirectResponse
    {
        abort_if($emails->requiresReview($this->member($request)), 409, 'Confirm your city email before continuing.');
        $request->session()->forget(EnsureCityEmailReview::SESSION_KEY);
        $destination = $request->session()->pull(EnsureCityEmailReview::RETURN_KEY, '/');

        return redirect(EnsureCityEmailReview::isSafeReturnPath($destination) ? $destination : '/');
    }

    private function member(Request $request): User
    {
        $user = $request->user('web');
        abort_unless($user instanceof User && $user->isAuthenticationAllowed() && $user->employeeProfile()->exists(), 403);

        return $user;
    }

    private function throttle(Request $request, User $user): void
    {
        $key = 'city-email-http:'.hash_hmac('sha256', $user->id.'|'.$request->ip(), (string) config('app.key'));
        if (RateLimiter::tooManyAttempts($key, 6)) {
            abort(429, 'Too many attempts. Please wait a minute before trying again.');
        }
        RateLimiter::hit($key, 60);
    }
}
