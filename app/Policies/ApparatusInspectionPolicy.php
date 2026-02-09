<?php

namespace App\Policies;

use App\Models\User;
use App\Models\ApparatusInspection;
use Illuminate\Auth\Access\HandlesAuthorization;

class ApparatusInspectionPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_inspection');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ApparatusInspection $apparatusInspection): bool
    {
        return $user->can('view_inspection');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create_inspection');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ApparatusInspection $apparatusInspection): bool
    {
        return $user->can('update_inspection');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ApparatusInspection $apparatusInspection): bool
    {
        return $user->can('delete_inspection');
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_inspection');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, ApparatusInspection $apparatusInspection): bool
    {
        return $user->can('force_delete_inspection');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_inspection');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, ApparatusInspection $apparatusInspection): bool
    {
        return $user->can('restore_inspection');
    }

    /**
     * Determine whether the user can bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_inspection');
    }

    /**
     * Determine whether the user can replicate.
     */
    public function replicate(User $user, ApparatusInspection $apparatusInspection): bool
    {
        return $user->can('replicate_inspection');
    }

    /**
     * Determine whether the user can reorder.
     */
    public function reorder(User $user): bool
    {
        return $user->can('reorder_inspection');
    }
}
