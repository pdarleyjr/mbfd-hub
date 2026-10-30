<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HubPushSubscription;
use App\Models\User;
use App\Notifications\TestPushNotification;
use App\Services\Notifications\PushDeliveryTrace;
use App\Services\Notifications\PushDeviceStatus;
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

        $own = fn () => $user->pushSubscriptions()
            ->when($endpoints !== null, fn ($query) => $query->whereIn('endpoint', $endpoints));

        $targeted = $own()->get();
        if ($targeted->isEmpty()) {
            return response()->json(['error' => 'No push devices found. Turn on alerts for this device first.'], 400);
        }

        $startedAt = now()->subSecond();
        $user->notifyNow(new TestPushNotification($endpoints));

        // A push service that answers 404/410 gets the device removed, so it is looked up again by id:
        // a device that is gone is reported as needing setup, never silently left out of the answer.
        $after = $own()->get()->keyBy(fn (HubPushSubscription $device): int => (int) $device->getKey());

        return response()->json([
            'success' => true,
            'message' => 'Test notification sent.',
            'note' => PushDeviceStatus::EXPLANATION,
            'devices' => $targeted
                ->map(function (HubPushSubscription $before) use ($after, $startedAt): array {
                    $provider = PushDeliveryTrace::providerFamily($before->endpoint);
                    $device = $after->get((int) $before->getKey());

                    if ($device === null) {
                        return [
                            'label' => $before->device_label ?? 'Unknown device',
                            'accepted' => false,
                            'failure' => 'subscription_expired',
                            'provider' => $provider,
                            'status' => PushDeviceStatus::SETUP_NEEDED,
                        ];
                    }

                    $accepted = $device->last_success_at !== null && $device->last_success_at->greaterThan($startedAt);

                    return [
                        'label' => $device->device_label ?? 'Unknown device',
                        'accepted' => $accepted,
                        'failure' => $device->last_failure_at !== null && $device->last_failure_at->greaterThan($startedAt)
                            ? $device->last_failure_reason
                            : null,
                        'provider' => $provider,
                        'status' => $accepted ? PushDeviceStatus::ACCEPTED : PushDeviceStatus::for($device),
                    ];
                })
                ->values(),
        ]);
    }
}
