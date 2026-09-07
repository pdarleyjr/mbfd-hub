<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\StationInventoryAudit;
use App\Models\StationSupplyRequest;
use App\Models\User;
use App\Notifications\StationSupplyRequestStatusNotification;
use App\Services\Display\DisplaySnapshotService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class StationSupplyRequestWorkflowService
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'open' => ['ordered', 'denied'],
        'ordered' => ['replenished'],
        'replenished' => [],
        'denied' => [],
    ];

    public function transition(StationSupplyRequest $request, string $status, User $actor, ?string $adminNote = null): StationSupplyRequest
    {
        $adminNote = filled($adminNote) ? trim((string) $adminNote) : null;

        return DB::transaction(function () use ($request, $status, $actor, $adminNote): StationSupplyRequest {
            $locked = StationSupplyRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            if ($locked->status === $status) {
                return $locked;
            }
            if (! in_array($status, self::TRANSITIONS[$locked->status] ?? [], true)) {
                throw new InvalidArgumentException("Cannot transition a station supply request from {$locked->status} to {$status}.");
            }
            if ($status === 'denied' && $adminNote === null) {
                throw new InvalidArgumentException('A denial note is required.');
            }

            $previousStatus = $locked->status;
            $locked->update([
                'status' => $status,
                'admin_notes' => $adminNote ?? $locked->admin_notes,
            ]);
            StationInventoryAudit::query()->create([
                'station_id' => $locked->station_id,
                'inventory_item_id' => null,
                'actor_user_id' => $actor->getKey(),
                'actor_employee_id' => $actor->employee_profile_id,
                'actor_name' => $actor->name,
                'actor_shift' => 'Admin',
                'action' => 'supply_request_status_changed',
                'from_value' => ['request_id' => $locked->id, 'status' => $previousStatus],
                'to_value' => ['request_id' => $locked->id, 'status' => $status, 'admin_note' => $adminNote],
            ]);

            DB::afterCommit(function () use ($locked): void {
                $this->forgetReadModels($locked);
                $recipient = $locked->actorUser;
                if ($recipient instanceof User) {
                    $recipient->notify(new StationSupplyRequestStatusNotification(
                        (int) $locked->station_id,
                        (string) $locked->status,
                    ));
                }
            });

            return $locked;
        }, 3);
    }

    private function forgetReadModels(StationSupplyRequest $request): void
    {
        Cache::forget(DisplaySnapshotService::SNAPSHOT_CACHE_KEY);
        Cache::forget(DisplaySnapshotService::STATIONS_CACHE_KEY);
        Cache::forget("station.{$request->station_id}.detail");
        Cache::forget("station.{$request->station_id}.activity");
        Cache::forget("station.{$request->station_id}.inventory");
    }
}
