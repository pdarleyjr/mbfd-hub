<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\HubPushSubscription;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Minishlink\WebPush\MessageSentReport;
use Throwable;

/**
 * One structured, privacy-preserving log line per push attempt, so "did the Hub hand this alert to
 * Apple/Google/Microsoft/Mozilla, and what did they answer?" can be answered from the existing log.
 *
 * Never logged: the endpoint or any part of it beyond a 10 character hash, keys, the auth secret,
 * the payload, device label, user agent, or the push service's raw error text (which quotes the
 * endpoint). A correlation id ties together everything one fan-out or send produced, including the
 * deferred retries for devices that were waiting out a back-off.
 *
 * Tracing can never fail a delivery: every public method swallows its own errors.
 */
final class PushDeliveryTrace
{
    public const OUTCOME_ACCEPTED = 'accepted';

    public const OUTCOME_REJECTED = 'rejected';

    public const OUTCOME_EXPIRED = 'expired';

    public const OUTCOME_BACKOFF = 'backoff';

    public const PROVIDER_APPLE = 'apple';

    public const PROVIDER_FCM = 'fcm';

    public const PROVIDER_MOZILLA = 'mozilla';

    public const PROVIDER_WNS = 'wns';

    public const PROVIDER_OTHER = 'other';

    public const LOG_MESSAGE = 'Push delivery attempt';

    public static function newCorrelationId(): string
    {
        return (string) Str::uuid();
    }

    /** The correlation id the notification already carries, else a fresh one for this send. */
    public static function correlationIdFor(Notification $notification): string
    {
        $carried = self::carriedCorrelationId($notification);

        return $carried ?? self::newCorrelationId();
    }

    /** Stamp the notification (when it can carry an id) unless it already has one; returns the id in force. */
    public static function stamp(Notification $notification, ?string $correlationId = null): string
    {
        $carried = self::carriedCorrelationId($notification);
        if ($carried !== null) {
            return $carried;
        }

        $correlationId ??= self::newCorrelationId();
        if (method_exists($notification, 'withDeliveryCorrelation')) {
            $notification->withDeliveryCorrelation($correlationId);
        }

        return $correlationId;
    }

    /** apple | fcm | mozilla | wns | other, from the endpoint's host only. */
    public static function providerFamily(?string $endpoint): string
    {
        $host = is_string($endpoint) ? parse_url($endpoint, PHP_URL_HOST) : null;
        if (! is_string($host) || $host === '') {
            return self::PROVIDER_OTHER;
        }
        $host = strtolower(rtrim($host, '.'));

        return match (true) {
            $host === 'push.apple.com' || str_ends_with($host, '.push.apple.com') => self::PROVIDER_APPLE,
            $host === 'googleapis.com' || str_ends_with($host, '.googleapis.com') => self::PROVIDER_FCM,
            $host === 'push.services.mozilla.com' || str_ends_with($host, '.push.services.mozilla.com') => self::PROVIDER_MOZILLA,
            str_ends_with($host, '.notify.windows.com') => self::PROVIDER_WNS,
            default => self::PROVIDER_OTHER,
        };
    }

    /** Plain name for the admin page and the test-button result. */
    public static function providerLabel(string $family): string
    {
        return match ($family) {
            self::PROVIDER_APPLE => 'Apple',
            self::PROVIDER_FCM => 'Google',
            self::PROVIDER_MOZILLA => 'Mozilla',
            self::PROVIDER_WNS => 'Microsoft',
            default => 'Other',
        };
    }

    /** First 10 characters of the endpoint's sha256: enough to match a device across log lines, useless as an address. */
    public static function endpointHash(?string $endpoint): string
    {
        return substr(hash('sha256', (string) $endpoint), 0, 10);
    }

    /** accepted | expired (404/410, device removed) | backoff (429, 5xx, no answer) | rejected (any other refusal). */
    public static function outcome(MessageSentReport $report): string
    {
        if ($report->isSuccess()) {
            return self::OUTCOME_ACCEPTED;
        }
        if ($report->isSubscriptionExpired()) {
            return self::OUTCOME_EXPIRED;
        }

        return PushDeliveryOutcomeRecorder::isTransient(self::statusOf($report))
            ? self::OUTCOME_BACKOFF
            : self::OUTCOME_REJECTED;
    }

    public static function statusOf(MessageSentReport $report): ?int
    {
        return $report->getResponse()?->getStatusCode();
    }

    /**
     * The push service's own short reason (Apple: "BadJwtToken", "TooManyProviderTokenUpdates"), only when
     * it is a plain code. The report's text is never used: it quotes the full endpoint.
     */
    public static function providerReason(MessageSentReport $report): ?string
    {
        try {
            $body = $report->getResponseContent();
            $decoded = is_string($body) && $body !== '' ? json_decode($body, true) : null;
            $reason = is_array($decoded) ? ($decoded['reason'] ?? null) : null;

            return is_string($reason) && preg_match('/^[A-Za-z0-9_.-]{1,60}$/', $reason) === 1 ? $reason : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Log one attempt. $report is null when the device was due an alert but was not tried because it is
     * waiting out a back-off (outcome backoff, no provider status, attempted false).
     *
     * @param  int  $attempt  1 at the original send; n + 1 for the nth deferred retry.
     */
    public static function record(
        Notification $notification,
        User $user,
        HubPushSubscription $device,
        string $correlationId,
        int $attempt,
        ?MessageSentReport $report,
        int $durationMs,
    ): void {
        try {
            $outcome = $report === null ? self::OUTCOME_BACKOFF : self::outcome($report);
            $context = [
                'correlation_id' => $correlationId,
                'notification_class' => $notification::class,
                'category_key' => self::categoryOf($notification),
                'notification_id' => $notification->id,
                'user_id' => (int) $user->getKey(),
                'device_id' => (int) $device->getKey(),
                'endpoint_hash' => self::endpointHash($device->endpoint),
                'provider' => self::providerFamily($device->endpoint),
                'content_encoding' => $device->content_encoding,
                'attempt' => $attempt,
                'attempted' => $report !== null,
                'provider_status' => $report === null ? null : self::statusOf($report),
                'outcome' => $outcome,
                'duration_ms' => $durationMs,
            ];
            if ($report !== null && $outcome !== self::OUTCOME_ACCEPTED && ($reason = self::providerReason($report)) !== null) {
                $context['provider_reason'] = $reason;
            }

            $outcome === self::OUTCOME_ACCEPTED
                ? Log::info(self::LOG_MESSAGE, $context)
                : Log::warning(self::LOG_MESSAGE, $context);
        } catch (Throwable) {
            // Tracing is observability only; it must never fail a delivery.
        }
    }

    private static function carriedCorrelationId(Notification $notification): ?string
    {
        $carried = $notification->deliveryCorrelationId ?? null;

        return is_string($carried) && $carried !== '' ? $carried : null;
    }

    private static function categoryOf(Notification $notification): ?string
    {
        try {
            return method_exists($notification, 'deliveryCategoryKey') ? $notification->deliveryCategoryKey() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
