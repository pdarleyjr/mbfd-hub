<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PromotionalList;
use App\Models\User;
use App\Services\Personnel\Promotions\PromotionalListAccess;

/** Thin wrapper so Filament and Gate checks use the same server-side rules as the service layer. */
class PromotionalListPolicy
{
    public function __construct(private readonly PromotionalListAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->canView($user, false);
    }

    public function view(User $user, PromotionalList $list): bool
    {
        return $this->access->canView($user, false);
    }

    public function create(User $user): bool
    {
        return $this->access->canManage($user, false);
    }

    public function update(User $user, PromotionalList $list): bool
    {
        return $this->access->canManage($user, false);
    }

    /** Lists are retained for the record; change their status instead of deleting. */
    public function delete(User $user, PromotionalList $list): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
