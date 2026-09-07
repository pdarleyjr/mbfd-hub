<?php

namespace App\Policies;

use App\Models\StationInventorySubmission;
use App\Models\User;
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
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, StationInventorySubmission $stationInventorySubmission): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, StationInventorySubmission $stationInventorySubmission): bool
    {
        return false;
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, StationInventorySubmission $stationInventorySubmission): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, StationInventorySubmission $stationInventorySubmission): bool
    {
        return false;
    }

    /**
     * Determine whether the user can bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can replicate.
     */
    public function replicate(User $user, StationInventorySubmission $stationInventorySubmission): bool
    {
        return false;
    }

    /**
     * Determine whether the user can reorder.
     */
    public function reorder(User $user): bool
    {
        return false;
    }
}
