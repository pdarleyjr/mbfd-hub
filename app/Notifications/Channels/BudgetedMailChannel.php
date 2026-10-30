<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Exceptions\EmailBudgetExhausted;
use App\Jobs\DeliverDeferredEmail;
use App\Models\Employee;
use App\Models\User;
use App\Services\Communications\CloudflareEmailDispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use LogicException;

final class BudgetedMailChannel
{
    /** Re-send attempts after the email budget's per-minute limit refused an alert, before giving up. */
    public const MAX_DEFERRALS = 6;

    public function __construct(private readonly CloudflareEmailDispatcher $dispatcher) {}

    public function send(object $notifiable, Notification $notification): void
    {
        $this->sendAttempt($notifiable, $notification, 1);
    }

    /**
     * A burst of alerts can exceed the provider's per-minute recipient limit (CloudflareCostGuard). The
     * queue retried the whole notification job at once (no backoff), so the email was lost after three
     * immediate refusals. The dispatcher proves nothing was submitted, so the email is re-sent once the
     * window it names has passed, a bounded number of times. In-app and push copies are unaffected.
     */
    public function sendAttempt(object $notifiable, Notification $notification, int $attempt): void
    {
        if (! method_exists($notification, 'toBudgetedEmail')) {
            throw new LogicException('Budgeted email notifications must define toBudgetedEmail().');
        }

        $payload = $notification->toBudgetedEmail($notifiable);
        $cityEmail = match (true) {
            $notifiable instanceof User => $notifiable->employeeProfile?->city_email,
            $notifiable instanceof Employee => $notifiable->city_email,
            method_exists($notifiable, 'routeNotificationFor') => $notifiable->routeNotificationFor('mail', $notification),
            default => null,
        };
        if (is_array($cityEmail)) {
            $cityEmail = array_key_first($cityEmail);
        }
        if (! is_string($cityEmail) || $cityEmail === '') {
            return;
        }

        try {
            $this->dispatcher->send(
                to: [$cityEmail],
                subject: (string) $payload['subject'],
                text: isset($payload['text']) ? (string) $payload['text'] : null,
                html: isset($payload['html']) ? (string) $payload['html'] : null,
                sourceType: $notification::class,
                sourceId: $notification->id,
            );
        } catch (EmailBudgetExhausted $exception) {
            $context = ['notification' => $notification::class, 'attempt' => $attempt, 'retry_after' => $exception->retryAfterSeconds];

            if ($exception->retryAfterSeconds === null || ! $notifiable instanceof Model || $attempt >= self::MAX_DEFERRALS) {
                // The cycle ceiling (no retry window) or repeated refusals: the email is not sent. The
                // dispatcher already recorded the failed delivery; the in-app and push copies stand.
                Log::warning('Alert email not sent: email budget exhausted', $context);

                return;
            }

            Log::info('Alert email deferred by the email budget', $context);
            DeliverDeferredEmail::dispatch($notification, $notifiable, $attempt + 1)
                ->delay(now()->addSeconds(max(1, $exception->retryAfterSeconds)));
        }
    }
}
