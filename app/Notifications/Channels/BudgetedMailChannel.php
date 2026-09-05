<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Models\Employee;
use App\Models\User;
use App\Services\Communications\CloudflareEmailDispatcher;
use Illuminate\Notifications\Notification;
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

        $this->dispatcher->send(
            to: [$cityEmail],
            subject: (string) $payload['subject'],
            text: isset($payload['text']) ? (string) $payload['text'] : null,
            html: isset($payload['html']) ? (string) $payload['html'] : null,
            sourceType: $notification::class,
            sourceId: $notification->id,
        );
    }
}
