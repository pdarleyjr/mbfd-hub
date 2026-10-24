<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HubPushSubscription;
use App\Models\User;
use App\Notifications\TestPushNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TestNotificationController extends Controller
{
    /**
     * Sends a test alert to the signed-in member's own push devices (or one of
     * them when `endpoint` is given) and reports what each device did.
     */
    public function sendTestNotification(Request $request): JsonResponse
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (! $user->isAuthenticationAllowed()) {
            return response()->json(['error' => 'This account cannot receive alerts.'], 403);
        }

        $validated = $request->validate(['endpoint' => ['nullable', 'string', 'max:500']]);
        $endpoints = is_string($validated['endpoint'] ?? null) ? [$validated['endpoint']] : null;

        $devices = $user->pushSubscriptions()
            ->when($endpoints !== null, fn ($query) => $query->whereIn('endpoint', $endpoints))
            ->count();
        if ($devices === 0) {
            return response()->json(['error' => 'No push devices found. Turn on alerts for this device first.'], 400);
        }

        $startedAt = now()->subSecond();
        $user->notifyNow(new TestPushNotification($endpoints));

        return response()->json([
            'success' => true,
            'message' => 'Test notification sent.',
            'devices' => $user->pushSubscriptions()
                ->when($endpoints !== null, fn ($query) => $query->whereIn('endpoint', $endpoints))
                ->get()
                ->map(fn (HubPushSubscription $device): array => [
                    'label' => $device->device_label ?? 'Unknown device',
                    'accepted' => $device->last_success_at !== null && $device->last_success_at->greaterThan($startedAt),
                    'failure' => $device->last_failure_at !== null && $device->last_failure_at->greaterThan($startedAt)
                        ? $device->last_failure_reason
                        : null,
                ])
                ->values(),
        ]);
    }
}
