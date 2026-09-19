<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\HubSupportTicket;
use App\Models\User;

final class HubSupportTicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->can($user, 'admin.support.view');
    }

    public function view(User $user, HubSupportTicket $ticket): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, HubSupportTicket $ticket): bool
    {
        return $this->can($user, 'admin.support.manage');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, HubSupportTicket $ticket): bool
    {
        return false;
    }

    private function can(User $user, string $permission): bool
    {
        return $user->isAuthenticationAllowed()
            && $user->hasCurrentAdminPanelEntitlement()
            && $user->hasDirectWebPermission($permission);
    }
}
