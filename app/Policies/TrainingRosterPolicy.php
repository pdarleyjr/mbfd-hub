<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingSession;
use App\Models\User;

class TrainingRosterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAuthenticationAllowed()
            && $user->hasAnyRole(['super_admin', 'training_admin', 'training_viewer']);
    }

    public function view(User $user, TrainingCourse|TrainingSession $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->isAuthenticationAllowed()
            && $user->hasAnyRole(['super_admin', 'training_admin']);
    }

    public function update(User $user, TrainingCourse|TrainingSession $record): bool
    {
        return $this->create($user)
            && (! $record instanceof TrainingSession || in_array($record->status, ['draft', 'open', 'closed'], true));
    }

    public function open(User $user, TrainingSession $record): bool
    {
        return $this->create($user) && in_array($record->status, ['draft', 'closed'], true);
    }

    public function close(User $user, TrainingSession $record): bool
    {
        return $this->create($user) && $record->status === 'open';
    }

    public function reopen(User $user, TrainingSession $record): bool
    {
        return $this->create($user) && in_array($record->status, ['certified', 'submitted'], true);
    }

    public function correct(User $user, TrainingSession $record): bool
    {
        return $this->create($user) && in_array($record->status, ['open', 'closed'], true);
    }

    public function certify(User $user, TrainingSession $record): bool
    {
        return $this->create($user) && in_array($record->status, ['closed', 'submitted'], true);
    }

    public function archive(User $user, TrainingSession $record): bool
    {
        return $this->create($user) && in_array($record->status, ['closed', 'submitted', 'certified'], true);
    }

    public function export(User $user, TrainingSession $record): bool
    {
        return $this->viewAny($user);
    }

    public function launch(User $user, TrainingSession $record): bool
    {
        return $this->create($user) && $record->status === 'open';
    }

    public function manageKiosk(User $user, TrainingSession $record): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, TrainingCourse|TrainingSession $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, TrainingCourse|TrainingSession $record): bool
    {
        return false;
    }
}
