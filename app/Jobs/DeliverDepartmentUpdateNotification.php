<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\DepartmentUpdate;
use App\Models\DepartmentUpdateNotificationDelivery;
use App\Notifications\DepartmentUpdateNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

final class DeliverDepartmentUpdateNotification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [15, 60];

    public int $uniqueFor = 300;

    public function __construct(public readonly int $deliveryId)
    {
        $this->onQueue('notifications');
    }

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    public function handle(): void
    {
        $delivery = DepartmentUpdateNotificationDelivery::query()
            ->with(['departmentUpdate', 'user'])
            ->find($this->deliveryId);
        if (! $delivery instanceof DepartmentUpdateNotificationDelivery
            || $delivery->delivered_at !== null
            || $delivery->cancelled_at !== null) {
            return;
        }

        $update = $delivery->departmentUpdate;
        $recipient = $delivery->user;
        if (! $update instanceof DepartmentUpdate) {
            $this->markCancelled($delivery, 'update_unavailable');

            return;
        }

        if (! $update->isActiveForNotificationDelivery($delivery->channel)) {
            $this->markCancelled($delivery, 'update_not_deliverable');

            return;
        }

        if ($recipient === null || ! $recipient->isAuthenticationAllowed()) {
            $this->markCancelled($delivery, 'recipient_unavailable');

            return;
        }

        $notification = DepartmentUpdateNotification::fromUpdate($update);
        if (! in_array($delivery->channel, $notification->via($recipient), true)) {
            $this->markCancelled($delivery, 'channel_unavailable');

            return;
        }

        $notification->id = $delivery->notification_id;
        if ($delivery->channel !== 'database' || ! DB::table('notifications')->where('id', $delivery->notification_id)->exists()) {
            Notification::sendNow($recipient, $notification, [$delivery->channel]);
        }

        $this->markDelivered($delivery);
    }

    private function markDelivered(DepartmentUpdateNotificationDelivery $delivery): void
    {
        $this->markTerminal($delivery, delivered: true);
    }

    private function markCancelled(DepartmentUpdateNotificationDelivery $delivery, string $reason): void
    {
        $this->markTerminal($delivery, delivered: false, cancellationReason: $reason);
    }

    private function markTerminal(
        DepartmentUpdateNotificationDelivery $delivery,
        bool $delivered,
        ?string $cancellationReason = null,
    ): void {
        DB::transaction(function () use ($delivery, $delivered, $cancellationReason): void {
            $current = DepartmentUpdateNotificationDelivery::query()->lockForUpdate()->find($delivery->id);
            if (! $current instanceof DepartmentUpdateNotificationDelivery
                || $current->delivered_at !== null
                || $current->cancelled_at !== null) {
                return;
            }

            $current->forceFill($delivered
                ? ['delivered_at' => now()]
                : ['cancelled_at' => now(), 'cancellation_reason' => $cancellationReason]
            )->saveQuietly();
            $update = DepartmentUpdate::query()->lockForUpdate()->find($current->department_update_id);
            if (! $update instanceof DepartmentUpdate) {
                return;
            }

            $hasPendingDeliveries = $update->notificationDeliveries()
                ->whereNull('delivered_at')
                ->whereNull('cancelled_at')
                ->exists();
            if (! $hasPendingDeliveries) {
                $hasCancelledDeliveries = $update->notificationDeliveries()->whereNotNull('cancelled_at')->exists();
                $update->forceFill([
                    'notification_sent_at' => $hasCancelledDeliveries ? null : now(),
                ])->saveQuietly();
            }
        });
    }
}
