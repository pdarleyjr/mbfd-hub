<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ApparatusInspectionException;
use App\Models\User;
use App\Services\Security\FleetOperationsManagerAssignment;

final class ApparatusInspectionExceptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'logistics_admin', FleetOperationsManagerAssignment::ROLE]) || $user->canManageFleetSwaps();
    }

    public function view(User $user, ApparatusInspectionException $exception): bool
    {
        return $user->can('approve', $exception->inspection);
    }

    public function update(User $user, ApparatusInspectionException $exception): bool
    {
        return $this->view($user, $exception);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, ApparatusInspectionException $exception): bool
    {
        return false;
    }
}
