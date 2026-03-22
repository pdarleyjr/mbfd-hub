<?php

namespace App\Policies;

use App\Models\User;
use App\Models\EvaluationSubmission;
use Illuminate\Auth\Access\HandlesAuthorization;

class EvaluationSubmissionPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_evaluation::submission');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, EvaluationSubmission $evaluationSubmission): bool
    {
        return $user->can('view_evaluation::submission');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create_evaluation::submission');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, EvaluationSubmission $evaluationSubmission): bool
    {
        // Only allow if draft or user is admin
        return $user->can('update_evaluation::submission');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, EvaluationSubmission $evaluationSubmission): bool
    {
        return $user->can('delete_evaluation::submission');
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_evaluation::submission');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, EvaluationSubmission $evaluationSubmission): bool
    {
        return $user->can('force_delete_evaluation::submission');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_evaluation::submission');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, EvaluationSubmission $evaluationSubmission): bool
    {
        return $user->can('restore_evaluation::submission');
    }

    /**
     * Determine whether the user can bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_evaluation::submission');
    }

    /**
     * Determine whether the user can replicate.
     */
    public function replicate(User $user, EvaluationSubmission $evaluationSubmission): bool
    {
        return $user->can('replicate_evaluation::submission');
    }

    /**
     * Determine whether the user can reorder.
     */
    public function reorder(User $user): bool
    {
        return $user->can('reorder_evaluation::submission');
    }

    /**
     * Determine whether the user can submit the evaluation.
     */
    public function submit(User $user, EvaluationSubmission $evaluationSubmission): bool
    {
        return $user->can('submit_evaluation::submission');
    }
}
