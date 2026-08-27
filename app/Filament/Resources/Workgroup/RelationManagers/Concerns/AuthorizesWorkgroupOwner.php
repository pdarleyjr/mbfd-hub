<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workgroup\RelationManagers\Concerns;

use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupSession;
use App\Support\Workgroups\WorkgroupAccess;
use Illuminate\Database\Eloquent\Model;

trait AuthorizesWorkgroupOwner
{
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user instanceof User && self::canManageOwnerRecord($user, $ownerRecord);
    }

    protected function can(string $action, ?Model $record = null): bool
    {
        return $this->canManageOwner();
    }

    public function canManageOwner(): bool
    {
        $user = auth()->user();

        return $user instanceof User && self::canManageOwnerRecord($user, $this->getOwnerRecord());
    }

    private static function canManageOwnerRecord(User $user, Model $ownerRecord): bool
    {
        $access = app(WorkgroupAccess::class);

        return match (true) {
            $ownerRecord instanceof Workgroup => $access->canManageWorkgroup($user, $ownerRecord),
            $ownerRecord instanceof WorkgroupSession => $access->canManageSession($user, $ownerRecord),
            default => false,
        };
    }
}
