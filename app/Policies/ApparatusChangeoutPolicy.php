<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ApparatusChangeout;
use App\Models\User;

/**
 * Fleet access reaches this model through the admin capability gate
 * (config/admin-capabilities.php): admin.fleet.view to see a changeout,
 * admin.fleet.manage to decide one, super administrators always. Anyone the
 * gate does not recognise lands here and is refused. Members submit and read
 * their own changeouts through the API, which ApparatusChangeoutService guards.
 */
final class ApparatusChangeoutPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, ApparatusChangeout $changeout): bool
    {
        return false;
    }

    public function decide(User $user): bool
    {
        return false;
    }

    public function delete(User $user, ApparatusChangeout $changeout): bool
    {
        return false;
    }
}
