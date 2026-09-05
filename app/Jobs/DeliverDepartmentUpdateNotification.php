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
        if (! $delivery instanceof DepartmentUpdateNotificationDelivery || $delivery->delivered_at !== null) {
            return;
        }

        $update = $delivery->departmentUpdate;
        $recipient = $delivery->user;
        if (! $update instanceof DepartmentUpdate || $recipient === null || ! $recipient->isAuthenticationAllowed()) {
            $this->markDelivered($delivery);

            return;
        }

        $notification = DepartmentUpdateNotification::fromUpdate($update);
        if (! in_array($delivery->channel, $notification->via($recipient), true)) {
            $this->markDelivered($delivery);

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
        DB::transaction(function () use ($delivery): void {
            $current = DepartmentUpdateNotificationDelivery::query()->lockForUpdate()->find($delivery->id);
            if (! $current instanceof DepartmentUpdateNotificationDelivery || $current->delivered_at !== null) {
                return;
            }

            $current->forceFill(['delivered_at' => now()])->saveQuietly();
            $update = DepartmentUpdate::query()->lockForUpdate()->find($current->department_update_id);
            if ($update instanceof DepartmentUpdate && ! $update->notificationDeliveries()->whereNull('delivered_at')->exists()) {
                $update->forceFill(['notification_sent_at' => now()])->saveQuietly();
            }
        });
    }
}
