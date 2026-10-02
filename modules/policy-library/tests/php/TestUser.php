<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Tests;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User;
use Spatie\Permission\Traits\HasRoles;

final class TestUser extends User implements FilamentUser
{
    use HasRoles;

    protected $table = 'users';

    protected $guarded = ['id'];

    public function hasCurrentAdminPanelEntitlement(): bool
    {
        return $this->hasRole('super_admin') || $this->permissions()->where('guard_name', 'web')->where('name', 'admin.access')->exists();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return false;
    }
}
