<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Security\AccountSecurityAction;
use App\Models\User;

final class AccountSecurityPolicy
{
    public function allows(User $actor, User $target, AccountSecurityAction $action): bool
    {
        if ($action === AccountSecurityAction::SelfServicePasswordChange) {
            return $actor->is($target);
        }

        if ($actor->is($target)) {
            return false;
        }

        return $actor->hasRole('super_admin')
            && in_array(
                $action->value,
                (array) config('security.account_security.allowed_administrative_actions', []),
                true,
            );
    }
}
