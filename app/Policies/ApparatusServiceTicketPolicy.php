<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ApparatusServiceTicket;
use App\Models\User;

class ApparatusServiceTicketPolicy
{
    private function manage(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'logistics_admin']);
    }

    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    public function view(User $user, ApparatusServiceTicket $ticket): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, ApparatusServiceTicket $ticket): bool
    {
        return $this->manage($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, ApparatusServiceTicket $ticket): bool
    {
        return false;
    }

    public function restore(User $user, ApparatusServiceTicket $ticket): bool
    {
        return false;
    }

    public function forceDelete(User $user, ApparatusServiceTicket $ticket): bool
    {
        return false;
    }
}
