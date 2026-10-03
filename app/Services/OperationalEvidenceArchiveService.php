<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OperationalFormEvent;
use App\Models\OperationalFormRecord;
use App\Models\StationInspection;
use App\Models\StationInventorySubmission;
use App\Models\TrtInventorySession;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OperationalEvidenceArchiveService
{
    public function archive(
        OperationalFormRecord|StationInspection|StationInventorySubmission|TrtInventorySession $record,
        User $actor,
        ?string $reason = null,
    ): void {
        $this->authorize($record, $actor);

        DB::transaction(function () use ($record, $actor, $reason): void {
            $locked = $this->lock($record);
            if ($locked instanceof OperationalFormRecord && $locked->trashed()) {
                abort(409, 'Restore the trashed form before archiving it.');
            }
            if ($locked instanceof TrtInventorySession && ! $locked->session_date->isBefore(today())) {
                throw ValidationException::withMessages([
                    'session' => 'Today’s shared inventory session must remain active for incoming submissions.',
                ]);
            }
            if ($locked->isArchived()) {
                return;
            }

            $locked->forceFill([
                'archived_at' => now(),
                'archived_by' => $actor->getKey(),
                'archive_reason' => filled($reason) ? trim((string) $reason) : null,
            ])->save();
            $this->audit($locked, $actor, 'record_archived', [
                'archived_at' => $locked->archived_at?->toISOString(),
                'archived_by' => $locked->archived_by,
                'archive_reason' => $locked->archive_reason,
            ]);
        }, 3);
        $record->refresh();
    }

    public function restore(
        OperationalFormRecord|StationInspection|StationInventorySubmission|TrtInventorySession $record,
        User $actor,
    ): void {
        $this->authorize($record, $actor);

        DB::transaction(function () use ($record, $actor): void {
            $locked = $this->lock($record);
            $trashed = $locked instanceof OperationalFormRecord && $locked->trashed();
            if (! $locked->isArchived() && ! $trashed) {
                return;
            }
            $archiveMetadata = [
                'archived_at' => $locked->archived_at?->toISOString(),
                'archived_by' => $locked->archived_by,
                'archive_reason' => $locked->archive_reason,
                'was_trashed' => $trashed,
            ];
            if ($locked instanceof OperationalFormRecord && $trashed) {
                $locked->restore();
            }
            $locked->forceFill(['archived_at' => null, 'archived_by' => null, 'archive_reason' => null])->save();
            $this->audit($locked, $actor, 'record_restored', $archiveMetadata);
        }, 3);
        $record->refresh();
    }

    private function authorize(Model $record, User $actor): void
    {
        $permission = match (true) {
            $record instanceof OperationalFormRecord => 'admin.forms.manage',
            $record instanceof TrtInventorySession => 'admin.equipment.manage',
            default => 'admin.stations.manage',
        };
        abort_unless($actor->can($permission), 403);
    }

    private function lock(
        OperationalFormRecord|StationInspection|StationInventorySubmission|TrtInventorySession $record,
    ): OperationalFormRecord|StationInspection|StationInventorySubmission|TrtInventorySession {
        $query = $record->newQuery();
        if ($record instanceof OperationalFormRecord) {
            $query->withTrashed();
        }

        /** @var OperationalFormRecord|StationInspection|StationInventorySubmission|TrtInventorySession $locked */
        $locked = $query->lockForUpdate()->findOrFail($record->getKey());

        return $locked;
    }

    /** @param array<string, mixed> $metadata */
    private function audit(Model $record, User $actor, string $event, array $metadata): void
    {
        if ($record instanceof OperationalFormRecord) {
            OperationalFormEvent::query()->create([
                'form_record_id' => $record->getKey(),
                'user_id' => $actor->getKey(),
                'event_type' => $event,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);
        }
    }
}
