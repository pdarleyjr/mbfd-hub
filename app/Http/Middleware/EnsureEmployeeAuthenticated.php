<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Identity\AuthenticatedMemberContextResolver;
use App\Services\Security\Command300Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureEmployeeAuthenticated
{
    public const UNLINKED_MESSAGE = '300 video conferencing needs a linked MBFD personnel profile. Ask a Hub administrator to link your account to your employee record.';

    public function __construct(
        private AuthenticatedMemberContextResolver $members,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! auth('web')->check()) {
            if ($request->expectsJson()) {
                abort(401);
            }

            return redirect()->guest(route('login'));
        }

        $actor = $this->members->resolve($request)->actor();

        // 300 video conferencing needs a personnel profile (participations are
        // keyed by employee). Tell an otherwise-authorized administrator exactly
        // what is missing instead of returning a generic denial.
        if ($actor->employee() === null
            && $request->routeIs('employee.video-conferencing.*')
            && app(Command300Access::class)->allows($actor->user())) {
            abort(403, self::UNLINKED_MESSAGE);
        }

        $actor->requireEmployee();

        return $next($request);
    }
}
