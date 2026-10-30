<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\HubPushSubscription;

/**
 * What the Hub honestly knows about one push device, in the five plain words the admin
 * delivery-health page and the "send a test" result both use.
 *
 * The Hub only learns what the push service (Apple, Google, Microsoft, Mozilla) answered. It can
 * never learn whether the phone displayed the alert, so no state here claims that.
 */
final class PushDeviceStatus
{
    public const ACCEPTED = 'Accepted by push service';

    public const REJECTED = 'Rejected by push service';

    public const SETUP_NEEDED = 'Device setup needed';

    public const WAITING = 'Waiting to retry';

    public const NEVER = 'Never attempted';

    public const EXPLANATION = 'Accepted means Apple/Google/Microsoft/Mozilla took the alert. Whether the phone displayed it is not observable by the server.';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::ACCEPTED, self::REJECTED, self::SETUP_NEEDED, self::WAITING, self::NEVER];
    }

    /**
     * Classified from the columns PushDeliveryOutcomeRecorder keeps current:
     * a back-off still running wins; otherwise a recorded failure reason says how the last attempt
     * ended (a success clears it); otherwise a recorded success; otherwise the device was never tried.
     * A push service that says 404/410 gets the device removed, so that status is mostly seen in the
     * test-button result; a row that still carries such a reason is classified the same way.
     */
    public static function for(HubPushSubscription $device): string
    {
        if ($device->backoff_until?->isFuture() === true) {
            return self::WAITING;
        }

        $reason = $device->last_failure_reason;
        if (is_string($reason) && $reason !== '') {
            return self::forFailureReason($reason);
        }

        return $device->last_success_at !== null ? self::ACCEPTED : self::NEVER;
    }

    /** The status for a failure reason such as "rejected (403)", "rate_limited (429)" or "no_response". */
    public static function forFailureReason(string $reason): string
    {
        $httpStatus = self::httpStatus($reason);

        return match (true) {
            $httpStatus === 404 || $httpStatus === 410 => self::SETUP_NEEDED,
            str_starts_with($reason, 'rejected') => self::REJECTED,
            default => self::WAITING,
        };
    }

    /** A sentence a firefighter can read in place of the recorder's short reason code. */
    public static function plainReason(?string $reason): ?string
    {
        if ($reason === null || $reason === '') {
            return null;
        }

        $httpStatus = self::httpStatus($reason);
        $suffix = $httpStatus === null ? '' : " (code {$httpStatus})";

        return match (true) {
            $httpStatus === 404 || $httpStatus === 410 => 'The push service no longer knows this device'.$suffix.'.',
            str_starts_with($reason, 'rate_limited') => 'The push service asked the Hub to slow down'.$suffix.'.',
            str_starts_with($reason, 'push_service_error') => 'The push service had a problem'.$suffix.'.',
            str_starts_with($reason, 'no_response') => 'The push service did not answer.',
            str_starts_with($reason, 'rejected') => 'The push service refused the alert'.$suffix.'.',
            default => 'The last attempt did not go through.',
        };
    }

    private static function httpStatus(string $reason): ?int
    {
        return preg_match('/\((\d{3})\)/', $reason, $match) === 1 ? (int) $match[1] : null;
    }
}
