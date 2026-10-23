<?php

declare(strict_types=1);

namespace App\Services\PersonnelRequests;

use App\Enums\PersonnelRequestType;
use App\Models\PersonnelRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class PersonnelRequestLifecycleService
{
    public function delete(PersonnelRequest $request, User $actor, string $reason): PersonnelRequest
    {
        $reason = trim($reason);
        validator(['deletion_reason' => $reason], ['deletion_reason' => ['required', 'string', 'max:2000']])->validate();

        return DB::transaction(function () use ($request, $actor, $reason): PersonnelRequest {
            $locked = PersonnelRequest::withTrashed()->lockForUpdate()->findOrFail($request->id);
            Gate::forUser($actor)->authorize('delete', $locked);
            if ($locked->type !== PersonnelRequestType::Uniform) {
                throw ValidationException::withMessages(['deletion_reason' => 'Only uniform orders can be moved to Trash with this action.']);
            }
            if ($locked->trashed()) {
                return $locked;
            }
            $locked->items()->orderBy('id')->lockForUpdate()->get();
            $locked->deleteToTrash($actor, $reason);
            $locked->updates()->create([
                'event' => 'deleted', 'status' => $locked->status, 'changed_by_admin_id' => $actor->id,
                'internal_note' => 'Moved to Trash. Reason: '.$reason,
                'metadata' => ['deleted_at' => $locked->deleted_at->toIso8601String(), 'deleted_by' => $actor->id, 'deletion_reason' => $reason],
            ]);

            return $locked;
        }, 3);
    }

    public function restore(PersonnelRequest $request, User $actor): PersonnelRequest
    {
        return DB::transaction(function () use ($request, $actor): PersonnelRequest {
            $locked = PersonnelRequest::withTrashed()->lockForUpdate()->findOrFail($request->id);
            Gate::forUser($actor)->authorize('restore', $locked);
            if ($locked->type !== PersonnelRequestType::Uniform) {
                throw ValidationException::withMessages(['deletion_reason' => 'Only uniform orders can be restored with this action.']);
            }
            if (! $locked->trashed()) {
                return $locked;
            }
            $metadata = ['deleted_at' => $locked->deleted_at->toIso8601String(), 'deleted_by' => $locked->deleted_by, 'deletion_reason' => $locked->deletion_reason];
            $locked->restoreFromTrash($actor);
            $locked->updates()->create([
                'event' => 'restored_from_trash', 'status' => $locked->status, 'changed_by_admin_id' => $actor->id,
                'internal_note' => 'Restored from Trash. Original workflow and archive state retained.', 'metadata' => $metadata,
            ]);

            return $locked;
        }, 3);
    }
}
