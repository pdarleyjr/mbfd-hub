<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Bid;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Bid\RevalidateBidIdentityRequest;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

final class IdentityRevalidationController extends Controller
{
    public function __invoke(RevalidateBidIdentityRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = User::query()
            ->with('employeeProfile:id,employee_id')
            ->find($validated['hub_user_id']);
        $employee = $user?->employeeProfile;

        if (! $user instanceof User
            || ! $user->isAuthenticationAllowed()
            || (int) $user->security_version !== $validated['security_version']
            || ! $employee instanceof Employee
            || (int) $employee->getKey() !== $validated['member_id']) {
            Log::info('bid.federation.revalidation', [
                'result' => 'failure',
                'category' => 'invalid_identity',
            ]);

            return response()->json(['error' => 'invalid_identity'], 401);
        }

        try {
            if (! $user->hasCurrentBidEntitlement()) {
                return response()->json(['error' => 'invalid_identity'], 401);
            }
            $role = $user->hasCurrentAdminPanelEntitlement() ? 'admin' : 'member';
        } catch (Throwable) {
            Log::info('bid.federation.revalidation', [
                'result' => 'failure',
                'category' => 'authorization_unavailable',
            ]);

            return response()->json(['error' => 'authorization_unavailable'], 503);
        }

        $response = response()->json([
            'issuer' => (string) config('services.bid.authorization.issuer'),
            'audience' => 'bid',
            'hub_user_id' => (int) $user->getKey(),
            'security_version' => (int) $user->security_version,
            'member_id' => (int) $employee->getKey(),
            'employee_id' => (string) $employee->employee_id,
            'role' => $role,
        ]);
        Log::info('bid.federation.revalidation', [
            'result' => 'success',
            'category' => 'revalidated',
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
