<?php

declare(strict_types=1);

namespace App\Http\Middleware;

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
        $this->members->resolve($request)->actor()->requireEmployee();

        return $next($request);
    }
}
