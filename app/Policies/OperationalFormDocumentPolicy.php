<?php

namespace App\Policies;

use App\Models\OperationalFormDocument;
use App\Models\User;

class OperationalFormDocumentPolicy
{
    public function view(User $user, OperationalFormDocument $document): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'logistics_admin']);
    }

    public function download(User $user, OperationalFormDocument $document): bool
    {
        return $this->view($user, $document);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, OperationalFormDocument $document): bool
    {
        return false;
    }

    public function delete(User $user, OperationalFormDocument $document): bool
    {
        return $this->view($user, $document);
    }
}
