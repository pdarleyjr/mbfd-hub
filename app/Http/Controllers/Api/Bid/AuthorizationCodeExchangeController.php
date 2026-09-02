<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Bid;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Bid\ExchangeBidAuthorizationCodeRequest;
use App\Models\Employee;
use App\Models\User;
use App\Services\Bid\BidAuthorizationCodeBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AuthorizationCodeExchangeController extends Controller
{
    public function __invoke(
        ExchangeBidAuthorizationCodeRequest $request,
        BidAuthorizationCodeBroker $codes,
    ): JsonResponse {
        $validated = $request->validated();
        $record = $codes->redeem(
            $validated['code'],
            $validated['client_id'],
            $validated['redirect_uri'],
        );

        if ($record === null) {
            Log::info('bid.federation.exchange', [
                'result' => 'failure',
                'category' => 'invalid_authorization_code',
            ]);

            return response()->json(['error' => 'invalid_authorization_code'], 401);
        }

        $user = User::query()
            ->with('employeeProfile:id,employee_id,name,rank')
            ->find($record['user_id']);
        $employee = $user?->employeeProfile;

        if (! $user instanceof User
            || ! $user->isAuthenticationAllowed()
            || (int) $user->security_version !== $record['security_version']
            || ! $employee instanceof Employee
            || (int) $employee->getKey() !== $record['employee_profile_id']) {
            Log::info('bid.federation.exchange', [
                'result' => 'failure',
                'category' => 'invalid_identity',
            ]);

            return response()->json(['error' => 'invalid_authorization_code'], 401);
        }

        try {
            $role = $user->hasCurrentAdminPanelEntitlement() ? 'admin' : 'member';
        } catch (Throwable) {
            Log::info('bid.federation.exchange', [
                'result' => 'failure',
                'category' => 'authorization_unavailable',
            ]);

            return response()->json(['error' => 'authorization_unavailable'], 503);
        }

        [$firstName, $lastName] = $this->splitName((string) $employee->name);
        $response = response()->json([
            'issuer' => $record['issuer'],
            'audience' => $record['audience'],
            'hub_user_id' => (int) $user->getKey(),
            'security_version' => (int) $user->security_version,
            'member_id' => (int) $employee->getKey(),
            'employee_id' => (string) $employee->employee_id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'rank' => (string) ($employee->rank ?? ''),
            'role' => $role,
        ]);
        Log::info('bid.federation.exchange', [
            'result' => 'success',
            'category' => 'exchanged',
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /** @return array{0: string, 1: string} */
    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName), 2);

        return [
            (string) ($parts[0] ?? ''),
            (string) ($parts[1] ?? ''),
        ];
    }
}
