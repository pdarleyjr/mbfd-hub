<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Identity\MemberBootstrapSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceMemberBootstrapBoundary
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->has(MemberBootstrapSession::KEY)) {
            return $next($request);
        }

        $user = app(MemberBootstrapSession::class)->current($request);
        if ($user === null) {
            return ! $request->expectsJson()
                ? redirect('/login')
                : response()->json(['message' => 'The onboarding session is no longer valid.'], 401);
        }

        if ($request->routeIs('member-onboarding.*')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Complete account onboarding before continuing.',
                'code' => 'bootstrap_onboarding_required',
            ], 403, ['Cache-Control' => 'no-store, private']);
        }

        return $request->isMethod('GET')
            ? redirect()->route('member-onboarding.show')
            : response('Complete account onboarding before continuing.', 403, ['Cache-Control' => 'no-store, private']);
    }
}
