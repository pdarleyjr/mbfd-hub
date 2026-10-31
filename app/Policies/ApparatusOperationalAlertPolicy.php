<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ApparatusOperationalAlert;
use App\Models\User;

/**
 * Fleet access reaches this model through the admin capability gate
 * (config/admin-capabilities.php): admin.fleet.view to see an alert,
 * admin.fleet.manage to change one, super administrators always. Anyone the
 * gate does not recognise lands here and is refused. Members read alerts on the
 * station page, through their own API, not through this policy.
 */
final class ApparatusOperationalAlertPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, ApparatusOperationalAlert $alert): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ApparatusOperationalAlert $alert): bool
    {
        return false;
    }

    public function delete(User $user, ApparatusOperationalAlert $alert): bool
    {
        return false;
    }
}
