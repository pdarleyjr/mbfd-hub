<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ApparatusVehicleSwapRequest;
use App\Models\User;

/**
 * Fleet access reaches this model through the admin capability gate
 * (config/admin-capabilities.php): admin.fleet.view to see a report,
 * admin.fleet.manage to decide one, super administrators always. Anyone the
 * gate does not recognise lands here and is refused, including members who
 * only hold the Daily Checkout.
 */
final class ApparatusVehicleSwapRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, ApparatusVehicleSwapRequest $request): bool
    {
        return false;
    }

    public function decide(User $user): bool
    {
        return false;
    }
}
