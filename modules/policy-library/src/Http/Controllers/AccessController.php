<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Http\Controllers;

use App\Models\AuthenticationSession;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

final class AccessController
{
    public function show(): View
    {
        return view('policy-library::access');
    }

    public function store(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->only('pin'), ['pin' => 'required|string|max:32']);
        if ($validator->fails()) {
            return back()->withErrors($validator);
        }
        $data = $validator->validated();
        $key = 'policy-library-pin:'.$request->user('web')->getAuthIdentifier().':'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['pin' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }
        $hash = config('policy-library.pin_hash');
        if (! is_string($hash) || $hash === '') {
            abort(503, 'Library access is temporarily unavailable.');
        }
        if (! Hash::check($data['pin'], $hash)) {
            RateLimiter::hit($key, 300);

            return back()->withErrors(['pin' => 'The access PIN is incorrect.']);
        }
        RateLimiter::clear($key);
        $registryId = $request->session()->get('auth.canonical_session_id');
        $oldSessionHash = hash_hmac('sha256', $request->session()->getId(), (string) config('app.key'));
        $request->session()->regenerate(true);
        if (is_string($registryId) && $registryId !== '') {
            $updated = AuthenticationSession::query()
                ->whereKey($registryId)
                ->where('user_id', $request->user('web')->getAuthIdentifier())
                ->where('session_id_hash', $oldSessionHash)
                ->whereNull('revoked_at')
                ->update([
                    'session_id_hash' => hash_hmac('sha256', $request->session()->getId(), (string) config('app.key')),
                ]);
            abort_unless($updated === 1, 401);
        }
        $request->session()->put('policy-library.access', [
            'user_id' => (string) $request->user('web')->getAuthIdentifier(),
            'expires_at' => now()->addMinutes(config('policy-library.pin_ttl_minutes'))->timestamp,
            'pin_version' => hash('sha256', $hash),
        ]);
        $intended = $request->session()->pull('policy-library.intended', '/');

        return redirect(is_string($intended) && str_starts_with($intended, '/') && ! str_starts_with($intended, '//') ? $intended : '/');
    }
}
