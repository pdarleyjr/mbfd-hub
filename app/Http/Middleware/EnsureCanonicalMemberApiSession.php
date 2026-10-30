<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\PasswordChangeRequired;
use App\Models\AuthenticationSession;
use App\Models\User;
use App\Services\Identity\SessionRegistry;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureCanonicalMemberApiSession
{
    /**
     * Sent by timers on pages left open (Daily Roster poll, station-list cards), never by a member's own action.
     * Every other check still runs; the request only leaves the idle clock where it is, so an unattended shared
     * screen still signs out. Spoofing it can only shorten the sender's own session, so it is not signed.
     */
    public const BACKGROUND_REFRESH_HEADER = 'X-Hub-Background-Refresh';

    public static function isBackgroundRefresh(Request $request): bool
    {
        return $request->headers->get(self::BACKGROUND_REFRESH_HEADER) === '1';
    }

    public function __construct(private SessionRegistry $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $webUser = $request->user('web');
        $registryId = $request->hasSession()
            ? $request->session()->get('auth.canonical_session_id')
            : null;
        $registered = is_string($registryId) && $registryId !== ''
            ? AuthenticationSession::query()->find($registryId)
            : null;

        if (! $user instanceof User
            || ! $webUser instanceof User
            || ! $user->is($webUser)
            || $request->bearerToken() !== null
            || ! $registered instanceof AuthenticationSession
            || ! $this->sessions->isCurrent($user, $registered, CarbonImmutable::now())) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! self::isBackgroundRefresh($request)) {
            $this->sessions->recordActivity($registered, CarbonImmutable::now());
        }

        if ($user->must_change_password) {
            throw new PasswordChangeRequired;
        }

        return $next($request);
    }
}
