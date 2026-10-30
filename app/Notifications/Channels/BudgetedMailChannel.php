<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Exceptions\EmailBudgetExhausted;
use App\Jobs\SendBudgetedEmail;
use App\Models\Employee;
use App\Models\OutboundEmail;
use App\Models\User;
use App\Services\Communications\CloudflareEmailDispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use LogicException;

final class BudgetedMailChannel
{
    public function __construct(private readonly CloudflareEmailDispatcher $dispatcher) {}

    public function send(object $notifiable, Notification $notification): void
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
        $subject = (string) $payload['subject'];
        $text = isset($payload['text']) ? (string) $payload['text'] : null;
        $html = isset($payload['html']) ? (string) $payload['html'] : null;

        $sourceId = (string) $notification->id;

        // A worker retry after a lost response must not send a second copy. The
        // ledger keeps the uncertain row, and the first failure was already reported.
        if ($this->mayHaveReachedProvider($notification, $sourceId)) {
            return;
        }
        try {
            $this->dispatcher->send(
                to: [$cityEmail],
                subject: $subject,
                text: $text,
                html: $html,
                sourceType: $notification::class,
                sourceId: $sourceId,
            );
        } catch (EmailBudgetExhausted $exception) {
            // The ledger holds the refusal. The notification's other channels stand,
            // and the worker's own retries would all land inside the same block.
            if ($exception->retryAfterSeconds === null) {
                Log::warning('budgeted_email_not_sent', ['source_type' => $notification::class, 'source_id' => $sourceId]);

                return;
            }
            SendBudgetedEmail::dispatch([$cityEmail], $subject, $text, $html, $notification::class, $sourceId, null,
                $notifiable instanceof Model ? $notification : null, $notifiable instanceof Model ? $notifiable : null,
            )->delay($exception->retryAfterSeconds);
        }
    }

    private function mayHaveReachedProvider(Notification $notification, string $sourceId): bool
    {
        return OutboundEmail::query()->where('source_type', $notification::class)->where('source_id', $sourceId)
            ->where('status', '!=', 'failed_pre_acceptance')->exists();
    }
}
