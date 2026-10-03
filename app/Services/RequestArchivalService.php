<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApparatusServiceTicket;
use App\Models\HubSupportTicket;
use App\Models\PersonnelRequest;
use App\Models\StationRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RequestArchivalService
{
    public function canManage(User $actor, PersonnelRequest|StationRequest|ApparatusServiceTicket|HubSupportTicket $record): bool
    {
        return $record instanceof StationRequest
            ? $actor->can('admin.stations.manage', 'web')
            : $actor->can('update', $record);
    }

    public function archive(PersonnelRequest|StationRequest|ApparatusServiceTicket|HubSupportTicket $record, User $actor, ?string $reason = null): PersonnelRequest|StationRequest|ApparatusServiceTicket|HubSupportTicket
    {
        $validated = validator(['archive_reason' => $reason], ['archive_reason' => ['nullable', 'string', 'max:2000']])->validate();

        return $this->change($record, $actor, true, trim((string) ($validated['archive_reason'] ?? '')) ?: null);
    }

    public function restore(PersonnelRequest|StationRequest|ApparatusServiceTicket|HubSupportTicket $record, User $actor): PersonnelRequest|StationRequest|ApparatusServiceTicket|HubSupportTicket
    {
        return $this->change($record, $actor, false);
    }

    private function change(PersonnelRequest|StationRequest|ApparatusServiceTicket|HubSupportTicket $record, User $actor, bool $archive, ?string $reason = null): PersonnelRequest|StationRequest|ApparatusServiceTicket|HubSupportTicket
    {
        abort_unless($this->canManage($actor, $record), 403);

        return DB::transaction(function () use ($record, $actor, $archive, $reason): PersonnelRequest|StationRequest|ApparatusServiceTicket|HubSupportTicket {
            /** @var PersonnelRequest|StationRequest|ApparatusServiceTicket|HubSupportTicket $locked */
            $locked = $record::query()->lockForUpdate()->findOrFail($record->getKey());
            abort_unless($this->canManage($actor, $locked), 403);
            if ($locked->isArchived() === $archive) {
                return $locked;
            }

            $metadata = [
                'event' => $archive ? 'archived' : 'restored',
                'archived_at' => $archive ? now()->toIso8601String() : $locked->archived_at->toIso8601String(),
                'archived_by' => $archive ? $actor->id : $locked->archived_by,
                'archive_reason' => $archive ? $reason : $locked->archive_reason,
            ];
            $locked->forceFill([
                'archived_at' => $archive ? now() : null,
                'archived_by' => $archive ? $actor->id : null,
                'archive_reason' => $archive ? $reason : null,
            ])->save();
            $update = [
                'status' => $locked->status,
                'internal_note' => $archive
                    ? 'Archived from active work.'.($reason !== null ? ' Reason: '.$reason : '')
                    : 'Restored to active work.',
                'metadata' => $metadata,
            ];
            if ($locked instanceof PersonnelRequest) {
                $update['event'] = $metadata['event'];
                $update['changed_by_admin_id'] = $actor->id;
            } else {
                $update['changed_by_user_id'] = $actor->id;
                if ($locked instanceof ApparatusServiceTicket || $locked instanceof HubSupportTicket) {
                    $update['previous_status'] = $locked->status;
                }
            }
            $locked->updates()->create($update);

            return $locked->refresh();
        }, 3);
    }
}
