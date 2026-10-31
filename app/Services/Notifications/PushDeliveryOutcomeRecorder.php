<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\HubPushSubscription;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\MessageSentReport;
use NotificationChannels\WebPush\Events\NotificationFailed;
use NotificationChannels\WebPush\Events\NotificationSent;

/**
 * Records what each device did with an alert, from the events the package's
 * report handler already dispatches.
 *
 * - accepted: remember when, clear any failure streak and back-off.
 * - 404/410: the package has already deleted the subscription; nothing to record.
 * - 429, 5xx and no response at all (the push service is busy or unreachable): count the failure
 *   and put the device on a SHORT back-off (Retry-After when sent, else 1 to 5 minutes doubling,
 *   never beyond 15). The channel re-sends the alert to that device once the back-off ends, so a
 *   back-off only delays a device; it never removes the alert or the channel.
 * - 400/401/403/413 (the Hub's own request was refused: keys, payload size): logged as the Hub's
 *   own error and counted, but with NO back-off, so one bad payload never silences a healthy phone.
 */
final class PushDeliveryOutcomeRecorder
{
    public const BACKOFF_BASE_MINUTES = 1;

    /** Exponential steps stop here when the push service gives no Retry-After. */
    public const BACKOFF_STEP_CAP_MINUTES = 5;

    /** The longest a device is ever held back, Retry-After included. */
    public const BACKOFF_MAX_MINUTES = 15;

    public function handle(NotificationSent|NotificationFailed $event): void
    {
        $subscription = $event->subscription;
        if (! $subscription instanceof HubPushSubscription || ! $subscription->exists) {
            return;
        }

        try {
            $event instanceof NotificationSent
                ? $this->recordSuccess($subscription)
                : $this->recordFailure($subscription, $event->report);
        } catch (\Throwable $exception) {
            // Health bookkeeping must never fail a delivery.
            Log::warning('Push delivery outcome could not be recorded', [
                'subscription_id' => $subscription->getKey(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /** True for the failures that mean "try this device again shortly" (not the Hub's own refused request). */
    public static function isTransient(?int $status): bool
    {
        return $status === null || $status === 429 || $status >= 500;
    }

    private function recordSuccess(HubPushSubscription $subscription): void
    {
        $subscription->forceFill([
            'last_success_at' => now(),
            'failure_count' => 0,
            'last_failure_reason' => null,
            'backoff_until' => null,
        ])->save();
    }

    private function recordFailure(HubPushSubscription $subscription, MessageSentReport $report): void
    {
        if ($report->isSubscriptionExpired()) {
            return;
        }

        $status = $report->getResponse()?->getStatusCode();
        $count = min(60000, ((int) $subscription->failure_count) + 1);
        $transient = self::isTransient($status);

        if (! $transient) {
            Log::error('Push service refused the Hub request; not a device fault', [
                'subscription_id' => $subscription->getKey(),
                'status' => $status,
                // Never $report->getReason(): the HTTP client's message quotes the full push endpoint.
                'provider_reason' => PushDeliveryTrace::providerReason($report),
            ]);
        }

        $subscription->forceFill([
            'last_failure_at' => now(),
            'failure_count' => $count,
            'last_failure_reason' => $this->reason($status),
            'backoff_until' => $transient ? $this->backoffUntil($status, $count, $report) : null,
        ])->save();
    }

    private function reason(?int $status): string
    {
        $label = match (true) {
            $status === 429 => 'rate_limited',
            $status !== null && $status >= 500 => 'push_service_error',
            $status !== null => 'rejected',
            default => 'no_response',
        };

        return $status === null ? $label : $label.' ('.$status.')';
    }

    private function backoffUntil(?int $status, int $count, MessageSentReport $report): CarbonInterface
    {
        $minutes = min(
            self::BACKOFF_STEP_CAP_MINUTES,
            self::BACKOFF_BASE_MINUTES * (2 ** min(max(1, $count) - 1, 10)),
        );

        // A push service that says how long to wait is believed, within the cap.
        $retryAfterSeconds = $this->retryAfterSeconds($report);
        if (($status === 429 || ($status !== null && $status >= 500)) && $retryAfterSeconds !== null) {
            $minutes = (int) ceil($retryAfterSeconds / 60);
        }

        return now()->addMinutes(max(1, min(self::BACKOFF_MAX_MINUTES, $minutes)));
    }

    /** Retry-After as seconds, from either the delay-seconds or the HTTP-date form. */
    private function retryAfterSeconds(MessageSentReport $report): ?int
    {
        $header = trim($report->getResponse()?->getHeaderLine('Retry-After') ?? '');
        if ($header === '') {
            return null;
        }
        if (ctype_digit($header)) {
            return (int) $header;
        }

        $at = strtotime($header);

        return $at === false ? null : max(0, $at - now()->getTimestamp());
    }
}
