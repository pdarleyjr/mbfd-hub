<?php

declare(strict_types=1);

namespace App\Http\Middleware\Narcotics;

use App\Models\User;
use App\Services\Narcotics\NarcoticsCheckAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Captain 5 narcotics check routes. The decision lives in NarcoticsCheckAccess::canReview(). */
final readonly class EnsureNarcoticsReviewer
{
    public function __construct(private NarcoticsCheckAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['ok' => false, 'error' => ['code' => 'unauthenticated', 'message' => 'Sign in to continue.', 'fields' => (object) []]], 401, ['Cache-Control' => 'private, no-store']);
        }
        if (! $this->access->canReview($user)) {
            return response()->json(['ok' => false, 'error' => ['code' => 'forbidden', 'message' => 'You do not have access to do that.', 'fields' => (object) []]], 403, ['Cache-Control' => 'private, no-store']);
        }

        return $next($request);
    }
}
