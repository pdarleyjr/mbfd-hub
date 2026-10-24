<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\User;

/**
 * Reads a member's current roles and permissions with as few queries as
 * possible while many categories are evaluated for the same member. It never
 * outlives one evaluation, so a revoked role is seen on the next decision.
 */
final class EligibilityContext
{
    /** @var list<string>|null */
    private ?array $directPermissions = null;

    private ?bool $active = null;

    public function __construct(public readonly User $user) {}

    /** The account is active and its roster identity is current. */
    public function isActive(): bool
    {
        return $this->active ??= $this->user->isAuthenticationAllowed();
    }

    public function hasAnyRole(string ...$roles): bool
    {
        return $this->user->hasAnyRole($roles);
    }

    /** A permission granted straight to the member (never inherited from a role). */
    public function hasDirectPermission(string $permission): bool
    {
        $this->directPermissions ??= $this->user->permissions()
            ->where('guard_name', 'web')
            ->pluck('name')
            ->all();

        return in_array($permission, $this->directPermissions, true);
    }

    public function can(string $ability, mixed $arguments = []): bool
    {
        return $this->user->can($ability, $arguments);
    }
}
