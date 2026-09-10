<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Identity\AuthenticatedMemberContextResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureCanonicalEmployeeContext
{
    public function __construct(
        private AuthenticatedMemberContextResolver $members,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');
        if ($user instanceof User && $user->must_change_password) {
            // The next middleware owns the restricted-session redirect and
            // allows only password replacement/logout. Do not resolve normal
            // member context before that boundary runs.
            return $next($request);
        }

        $this->members->resolve($request)->actor()->requireEmployee();

        return $next($request);
    }
}
