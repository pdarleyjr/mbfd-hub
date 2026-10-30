<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Captain5\Captain5ActingRequest;
use App\Models\User;
use App\Services\Captain5\Captain5ActingService;
use App\Services\Captain5\Captain5DutyRoster;
use App\Services\DailyRoster\OnDutyOfficerDisplayResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The shift's 300 (Division Chief) on the 300 page and the 300 card: who is shown now (approved
 * acting 300, otherwise today's accepted roster, otherwise the 300 bid holder for the shift on duty)
 * and "Acting as 300 today?". Display only. The route group needs `access-command-300`; the acting service re-checks who may
 * ask and who may approve, and these routes only ever decide 300 requests.
 */
final class Command300ShiftController extends Controller
{
    private const ROLE = Captain5ActingRequest::ROLE_COMMAND_300;

    public function show(Request $request): JsonResponse
    {
        return $this->json($this->state($this->user($request)));
    }

    public function requestActing(Request $request, Captain5ActingService $acting): JsonResponse
    {
        $acting->request($this->user($request), ['role' => self::ROLE] + $request->only(['kind', 'duration', 'ends_at', 'client_request_id']));

        return $this->json($this->state($this->user($request)), 201);
    }

    public function approveActing(Request $request, Captain5ActingService $acting, int $actingRequest): JsonResponse
    {
        $valid = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $acting->approve($this->user($request), $actingRequest, $valid['note'] ?? null, self::ROLE);

        return $this->json($this->state($this->user($request)));
    }

    public function rejectActing(Request $request, Captain5ActingService $acting, int $actingRequest): JsonResponse
    {
        $valid = $request->validate(['note' => ['required', 'string', 'max:500']]);
        $acting->reject($this->user($request), $actingRequest, $valid['note'], self::ROLE);

        return $this->json($this->state($this->user($request)));
    }

    public function withdrawActing(Request $request, Captain5ActingService $acting, int $actingRequest): JsonResponse
    {
        $acting->withdraw($this->user($request), $actingRequest, self::ROLE);

        return $this->json($this->state($this->user($request)));
    }

    /** @return array{on_duty: array<string, mixed>, acting: array<string, mixed>} */
    private function state(User $user): array
    {
        app(Captain5DutyRoster::class)->flush();

        return [
            'on_duty' => app(OnDutyOfficerDisplayResolver::class)->resolve(self::ROLE)->toArray(),
            'acting' => app(Captain5ActingService::class)->state($user, self::ROLE),
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthorizationException;
        }

        return $user;
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, ['Cache-Control' => 'private, no-store']);
    }
}
