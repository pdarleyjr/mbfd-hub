<?php

declare(strict_types=1);

namespace App\Services\Personnel\Promotions;

use App\Models\User;
use App\Services\Personnel\PersonnelTableService;
use App\Services\Security\ApplicationAccessService;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Server-side authorization for promotional lists.
 *
 * Viewing follows the Master Personnel Directory view permission. Changing lists needs the
 * direct personnel.promotions.manage capability (or super_admin). Exports follow the
 * directory export rules. Every check re-reads the account so a stale session cannot act.
 */
final class PromotionalListAccess
{
    public const MANAGE = 'personnel.promotions.manage';

    public function __construct(private readonly PersonnelTableService $tables) {}

    public function canView(User $actor, bool $refreshActor = true): bool
    {
        return $this->tables->canViewDirectory($actor, $refreshActor);
    }

    public function canManage(User $actor, bool $refreshActor = true): bool
    {
        $current = $this->current($actor, $refreshActor);

        return $current !== null && $this->tables->canViewDirectory($current, false)
            && ($current->hasRole('super_admin') || $current->hasDirectWebPermission(self::MANAGE));
    }

    public function canExport(User $actor): bool
    {
        $current = $this->current($actor, true);
        if ($current === null || ! $this->tables->canViewDirectory($current, false)) {
            return false;
        }
        if ($current->hasRole('super_admin') || $current->hasDirectWebPermission(self::MANAGE)
            || $current->hasDirectWebPermission('personnel.directory.export') || $current->hasDirectWebPermission('admin.members.manage')) {
            return true;
        }

        return $current->hasDirectWebPermission('admin.personnel.manage') && ! ApplicationAccessService::isUniformManagerOnly($current);
    }

    public function authorizeView(User $actor): void
    {
        if (! $this->canView($actor)) {
            throw new AuthorizationException;
        }
    }

    public function authorizeManage(User $actor): void
    {
        if (! $this->canManage($actor)) {
            throw new AuthorizationException;
        }
    }

    public function authorizeExport(User $actor): void
    {
        if (! $this->canExport($actor)) {
            throw new AuthorizationException;
        }
    }

    private function current(User $actor, bool $refresh): ?User
    {
        return $refresh ? User::query()->find($actor->getKey()) : $actor;
    }
}
