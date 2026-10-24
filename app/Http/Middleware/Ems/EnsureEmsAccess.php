<?php

declare(strict_types=1);

namespace App\Http\Middleware\Ems;

use App\Models\User;
use App\Services\Ems\EmsAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff EMS routes: `EnsureEmsAccess::class.':view'` (Captain 5 view OR Admin EMS view),
 * `:act` (Captain 5 act OR Admin EMS manage) or `:configure`. The decision lives in EmsAccess.
 */
final readonly class EnsureEmsAccess
{
    public function __construct(private EmsAccess $access) {}

    public function handle(Request $request, Closure $next, string $ability = 'view'): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['ok' => false, 'error' => ['code' => 'unauthenticated', 'message' => 'Sign in to continue.', 'fields' => (object) []]], 401, ['Cache-Control' => 'private, no-store']);
        }
        if (! $this->access->allows($user, $ability)) {
            return response()->json(['ok' => false, 'error' => ['code' => 'forbidden', 'message' => 'You do not have access to do that.', 'fields' => (object) []]], 403, ['Cache-Control' => 'private, no-store']);
        }

        return $next($request);
    }
}
