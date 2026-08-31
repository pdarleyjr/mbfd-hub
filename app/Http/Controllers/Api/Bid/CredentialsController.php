<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Bid;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Bid\VerifyCredentialsRequest;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Credentials-bridge controller for the MBFD Bid Cloudflare Worker.
 *
 * The bid Worker calls this endpoint when a member tries to log into the
 * bid app at https://bid.mbfdhub.com / https://staging.bid.mbfdhub.com.
 * Returns the canonical Employee Portal identity so the Worker can sign
 * its own JWT and issue a bid-app session cookie.
 *
 * Routes (registered in routes/api.php):
 *   POST /api/v2/verify-credentials  (verify.bid.token middleware)
 */
class CredentialsController extends Controller
{
    public function verifyCredentials(VerifyCredentialsRequest $request): JsonResponse
    {
        $employeeId = (string) $request->input('employee_id');
        $password = (string) $request->input('password');

        /** @var Employee|null $employee */
        $employee = Employee::query()
            ->where('employee_id', $employeeId)
            ->first();

        if ($employee === null || ! Hash::check($password, $employee->password)) {
            // Constant-time-ish: always log + always 401. Don't leak which
            // half of the credential pair was wrong.
            Log::channel(config('logging.default', 'stack'))->info(
                'bid.verify_credentials.invalid',
                ['employee_id' => $employeeId],
            );

            return response()->json(['error' => 'invalid_credentials'], 401);
        }

        // Split "First Last" name. The portal stores `name` as a single
        // string but the bid app expects first/last separately.
        [$firstName, $lastName] = self::splitName((string) $employee->name);

        // Employee Portal authentication and the Hub Admin Panel have
        // separate identity tables. The employee_id field is the only
        // established cross-identity key. Resolve the current Hub role for
        // every successful login; ambiguity or an unavailable entitlement is
        // never elevated. Neither password nor password hash leaves this
        // request boundary.
        $linkedUsers = User::query()
            ->where('employee_id', (string) $employee->employee_id)
            ->limit(2)
            ->get();
        $role = $linkedUsers->count() === 1 && $linkedUsers->first()?->canAdministerBid()
            ? 'admin'
            : 'member';

        return response()->json([
            'member_id' => (int) $employee->id,
            'employee_id' => (string) $employee->employee_id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'rank' => (string) ($employee->rank ?? ''),
            'role' => $role,
        ]);
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function splitName(string $full): array
    {
        $trimmed = trim($full);
        if ($trimmed === '') {
            return ['', ''];
        }
        $parts = preg_split('/\s+/', $trimmed, 2);
        if ($parts === false || count($parts) === 0) {
            return [$trimmed, ''];
        }
        $first = $parts[0] ?? '';
        $last = $parts[1] ?? '';

        return [$first, $last];
    }
}
