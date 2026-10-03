<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\StationInventoryAudit;
use App\Models\StationSupplyRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class StationSupplyRequestWorkflowService
{
    public function update(StationSupplyRequest $request, User $actor, array $data): void
    {
        $validated = validator($data, [
            'status' => ['required', 'in:open,ordered,replenished,denied'],
            'admin_notes' => ['nullable', 'string', 'max:5000'],
            'public_response' => ['nullable', 'string', 'max:5000'],
        ])->validate();
        $this->change($request, $actor, $validated, 'request_updated');
    }

    public function archive(StationSupplyRequest $request, User $actor, ?string $reason = null): void
    {
        $validated = validator(['reason' => $reason], ['reason' => ['nullable', 'string', 'max:2000']])->validate();
        $this->change($request, $actor, ['archived_at' => now(), 'archived_by' => $actor->id, 'archive_reason' => $validated['reason']], 'request_archived');
    }

    public function restore(StationSupplyRequest $request, User $actor): void
    {
        $this->change($request, $actor, ['archived_at' => null, 'archived_by' => null, 'archive_reason' => null], 'request_restored');
    }

    private function change(StationSupplyRequest $request, User $actor, array $data, string $event): void
    {
        abort_unless($actor->can('admin.stations.manage'), 403);
        DB::transaction(function () use ($request, $actor, $data, $event): void {
            $locked = StationSupplyRequest::query()->lockForUpdate()->findOrFail($request->id);
            if (($event === 'request_archived' && $locked->isArchived()) || ($event === 'request_restored' && ! $locked->isArchived())) {
                return;
            }
            abort_if($event === 'request_updated' && $locked->isArchived(), 409, 'Restore this request before updating it.');
            $before = $locked->only(array_keys($data));
            $locked->forceFill($data)->save();
            StationInventoryAudit::query()->create([
                'station_id' => $locked->station_id,
                'actor_user_id' => $actor->id,
                'actor_employee_id' => $actor->employee_profile_id,
                'actor_name' => $actor->display_name ?: $actor->name,
                'actor_shift' => $locked->created_by_shift,
                'action' => $event,
                'from_value' => ['request_id' => $locked->id, ...$before],
                'to_value' => ['request_id' => $locked->id, ...$locked->only(array_keys($data))],
            ]);
        }, 3);
        $request->refresh();
    }
}
