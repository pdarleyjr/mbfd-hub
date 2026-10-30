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
     * them when `endpoint` is given) and reports what each push service said.
     *
     * A push service accepting the test is NOT proof the phone showed it (focus
     * modes, notification settings and the device itself decide that), so the
     * wording never says "delivered". Per device:
     * - `label`, `accepted`, `failure`: unchanged keys, kept for older pages.
     * - `outcome`: accepted | rejected | retrying | expired | no_answer.
     * - `service`: Apple, Google, Microsoft, Mozilla, or null when unknown.
     * - `summary`: one plain sentence for the member.
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

        $devicesQuery = fn () => $user->pushSubscriptions()
            ->when($endpoints !== null, fn ($query) => $query->whereIn('endpoint', $endpoints));

        /** @var \Illuminate\Support\Collection<int, HubPushSubscription> $before */
        $before = $devicesQuery()->get();
        if ($before->isEmpty()) {
            return response()->json([
                'error' => 'No push devices found. Turn on alerts for this device first.',
                'outcome' => 'needs_setup',
            ], 400);
        }

        $startedAt = now()->subSecond();
        $user->notifyNow(new TestPushNotification($endpoints));

        $after = $devicesQuery()->get()->keyBy(fn (HubPushSubscription $device): int => (int) $device->getKey());

        $devices = $before->map(function (HubPushSubscription $original) use ($after, $startedAt): array {
            $device = $after->get((int) $original->getKey());
            $service = self::serviceName($original->endpoint);
            $label = $original->device_label ?? 'Unknown device';

            if (! $device instanceof HubPushSubscription) {
                // 404/410: the push service says this subscription no longer exists; the package removed it.
                return [
                    'label' => $label,
                    'accepted' => false,
                    'failure' => 'expired',
                    'outcome' => 'expired',
                    'service' => $service,
                    'summary' => 'This device needs to be set up again. On that device, turn alerts off and on.',
                    'provider' => PushDeliveryTrace::providerFamily($original->endpoint),
                    'status' => PushDeviceStatus::SETUP_NEEDED,
                ];
            }

            $accepted = $device->last_success_at !== null && $device->last_success_at->greaterThan($startedAt);
            $failure = $device->last_failure_at !== null && $device->last_failure_at->greaterThan($startedAt)
                ? $device->last_failure_reason
                : null;
            $outcome = $accepted ? 'accepted' : self::failureOutcome($failure);

            return [
                'label' => $device->device_label ?? $label,
                'accepted' => $accepted,
                'failure' => $failure,
                'outcome' => $outcome,
                'service' => $service,
                'summary' => self::summary($outcome, $service, $failure),
                'provider' => PushDeliveryTrace::providerFamily($original->endpoint),
                'status' => $accepted ? PushDeviceStatus::ACCEPTED : PushDeviceStatus::for($device),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Test handed to the push service. Check each device to see whether it appeared.',
            'note' => PushDeviceStatus::EXPLANATION,
            'devices' => $devices,
        ]);
    }

    /** The company that runs a device's push service, from its endpoint host. */
    public static function serviceName(string $endpoint): ?string
    {
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));

        return match (true) {
            $host === '' => null,
            $host === 'web.push.apple.com' || str_ends_with($host, '.push.apple.com') => 'Apple',
            str_ends_with($host, 'googleapis.com') => 'Google',
            str_ends_with($host, '.notify.windows.com') => 'Microsoft',
            str_ends_with($host, '.mozilla.com') || str_ends_with($host, '.mozaws.net') => 'Mozilla',
            default => null,
        };
    }

    private static function failureOutcome(?string $failure): string
    {
        if ($failure === null) {
            return 'no_answer';
        }

        return str_starts_with($failure, 'rejected') ? 'rejected' : 'retrying';
    }

    private static function summary(string $outcome, ?string $service, ?string $failure): string
    {
        $who = $service === null ? 'the push service' : $service.'\'s push service';

        return match ($outcome) {
            'accepted' => 'Accepted by '.$who.'. Check this device: if nothing appeared, check its notification and Focus settings.',
            'rejected' => 'Rejected by '.$who.': '.self::plainReason($failure).'. Turn alerts off and on for this device.',
            'retrying' => 'Not accepted yet: '.self::plainReason($failure).'. The Hub will try again on its own.',
            default => 'No answer from '.$who.' yet. Try again in a minute.',
        };
    }

    /** "rejected (403)" and friends, in words. */
    private static function plainReason(?string $failure): string
    {
        $code = $failure !== null && preg_match('/\((\d{3})\)/', $failure, $match) === 1 ? (int) $match[1] : null;

        return match (true) {
            $failure === null => 'no reason given',
            str_starts_with($failure, 'rate_limited') => 'the push service asked the Hub to slow down',
            str_starts_with($failure, 'push_service_error') => 'the push service had a problem'.($code ? ' (code '.$code.')' : ''),
            str_starts_with($failure, 'no_response') => 'the push service did not answer',
            $code === 413 => 'the alert was too large (code 413)',
            $code === 401 || $code === 403 => 'the Hub\'s sender key was not accepted (code '.$code.')',
            $code !== null => 'code '.$code,
            default => 'no reason given',
        };
    }
}
