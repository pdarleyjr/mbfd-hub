<?php

namespace App\Policies;

use App\Models\User;
use App\Models\StationInventorySubmission;
use Illuminate\Auth\Access\HandlesAuthorization;

class StationInventorySubmissionPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_station::inventory::submission');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, StationInventorySubmission $stationInventorySubmission): bool
    {
        return $user->can('view_station::inventory::submission');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create_station::inventory::submission');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, StationInventorySubmission $stationInventorySubmission): bool
    {
        return $user->can('update_station::inventory::submission');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, StationInventorySubmission $stationInventorySubmission): bool
    {
        return $user->can('delete_station::inventory::submission');
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_station::inventory::submission');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, StationInventorySubmission $stationInventorySubmission): bool
    {
        return $user->can('force_delete_station::inventory::submission');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_station::inventory::submission');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, StationInventorySubmission $stationInventorySubmission): bool
    {
        return $user->can('restore_station::inventory::submission');
    }

    /**
     * Determine whether the user can bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_station::inventory::submission');
    }

    /**
     * Determine whether the user can replicate.
     */
    public function replicate(User $user, StationInventorySubmission $stationInventorySubmission): bool
    {
        return $user->can('replicate_station::inventory::submission');
    }

    /**
     * Determine whether the user can reorder.
     */
    public function reorder(User $user): bool
    {
        return $user->can('reorder_station::inventory::submission');
    }
}
