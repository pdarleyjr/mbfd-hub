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
 * Transitional credentials bridge for the MBFD Bid Cloudflare Worker.
 *
 * The bid Worker calls this endpoint when a member tries to log into the
 * bid app at https://bid.mbfdhub.com / https://staging.bid.mbfdhub.com.
 * Returns the canonical Employee Portal identity so the Worker can sign
 * its own JWT and issue a bid-app session cookie.
 *
 * The active Bid source uses canonical Hub authorization codes. Retain this
 * route only until deployed telemetry proves the legacy caller is gone.
 *
 * Routes (registered in routes/api.php):
 *   POST /api/v2/verify-credentials  (verify.bid.reader middleware)
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
            Log::info('bid.legacy_verify_credentials', [
                'result' => 'failure',
                'category' => 'invalid_credentials',
            ]);

            return response()->json(['error' => 'invalid_credentials'], 401);
        }

        // Split "First Last" name. The portal stores `name` as a single
        // string but the bid app expects first/last separately.
        [$firstName, $lastName] = self::splitName((string) $employee->name);

        $response = response()->json([
            'member_id' => (int) $employee->id,
            'employee_id' => (string) $employee->employee_id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'rank' => (string) ($employee->rank ?? ''),
            'role' => self::resolveBidRole((string) $employee->employee_id),
        ]);
        Log::info('bid.legacy_verify_credentials', [
            'result' => 'success',
            'category' => 'verified',
        ]);

        return $response;
    }

    /**
     * Resolve the authoritative Hub Admin Panel entitlement on every fresh
     * credential exchange. Any unavailable entitlement lookup fails closed to
     * a member response; Bid never keeps its own administrator roster.
     */
    private static function resolveBidRole(string $employeeId): string
    {
        try {
            $user = User::query()
                ->where('employee_id', $employeeId)
                ->first();

            return $user?->hasCurrentAdminPanelEntitlement() === true ? 'admin' : 'member';
        } catch (\Throwable) {
            return 'member';
        }
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
