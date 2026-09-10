<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AuthenticationSession;
use App\Models\User;
use App\Services\Identity\SessionRegistry;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAdminCapability
{
    public function __construct(private readonly SessionRegistry $sessions) {}

    public function handle(Request $request, Closure $next, string ...$capabilities): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        $current = $user->fresh();

        $registryId = $request->hasSession()
            ? $request->session()->get('auth.canonical_session_id')
            : null;
        $registered = is_string($registryId) && $registryId !== ''
            ? AuthenticationSession::query()->find($registryId)
            : null;

        if (! $current instanceof User
            || ! $registered instanceof AuthenticationSession
            || ! $this->sessions->isCurrent($current, $registered, CarbonImmutable::now())) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $current->hasCurrentAdminPanelEntitlement()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $hasCapability = collect($capabilities)
            ->contains(fn (string $capability): bool => $current->hasPermissionTo($capability, 'web'));
        if (! $current->hasRole('super_admin', 'web')
            && ($capabilities === [] || ! $hasCapability)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
