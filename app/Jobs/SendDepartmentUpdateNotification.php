<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\DepartmentUpdate;
use App\Models\DepartmentUpdateNotificationDelivery;
use App\Notifications\DepartmentUpdateNotification;
use App\Services\DepartmentUpdates\DepartmentUpdateAudienceResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SendDepartmentUpdateNotification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [15, 60];

    public int $uniqueFor = 300;

    public function __construct(public readonly int $departmentUpdateId)
    {
        $this->onQueue('notifications');
    }

    public function uniqueId(): string
    {
        return (string) $this->departmentUpdateId;
    }

    public function handle(DepartmentUpdateAudienceResolver $audiences): void
    {
        $deliveryIds = DB::transaction(function () use ($audiences): array {
            $update = DepartmentUpdate::query()->lockForUpdate()->find($this->departmentUpdateId);
            if (! $update instanceof DepartmentUpdate) {
                return [];
            }

            if ($update->notification_prepared_at !== null) {
                return $update->notificationDeliveries()
                    ->whereNull('delivered_at')
                    ->whereNull('cancelled_at')
                    ->pluck('id')
                    ->all();
            }

            if (! $update->isDueForNotification()) {
                return [];
            }

            $notification = DepartmentUpdateNotification::fromUpdate($update);
            foreach ($audiences->resolve($update) as $recipient) {
                foreach ($notification->via($recipient) as $channel) {
                    DepartmentUpdateNotificationDelivery::query()->firstOrCreate([
                        'department_update_id' => $update->id,
                        'user_id' => $recipient->id,
                        'channel' => $channel,
                    ], [
                        'notification_id' => (string) Str::uuid(),
                    ]);
                }
            }

            $hasDeliveries = $update->notificationDeliveries()->exists();
            $update->forceFill([
                'notification_prepared_at' => now(),
                'notification_sent_at' => $hasDeliveries ? null : now(),
            ])->saveQuietly();

            return $update->notificationDeliveries()
                ->whereNull('delivered_at')
                ->whereNull('cancelled_at')
                ->pluck('id')
                ->all();
        });

        foreach ($deliveryIds as $deliveryId) {
            DeliverDepartmentUpdateNotification::dispatch($deliveryId);
        }
    }
}
