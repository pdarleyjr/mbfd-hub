<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Models\Ems\InventoryRequestEvent;
use App\Models\User;

/** Who did something, as the history will show it for years (name and rank are snapshots). */
final readonly class EmsActor
{
    public function __construct(
        public ?int $userId,
        public string $name,
        public ?string $rank,
        public string $context,
        public ?User $user = null,
    ) {}

    public static function forUser(User $user, string $context): self
    {
        $user->loadMissing('employeeProfile:id,employee_id,name,rank');
        $employee = $user->employeeProfile;
        $name = trim((string) ($employee?->name ?: $user->display_name ?: $user->name));

        return new self(
            (int) $user->getKey(),
            $name !== '' ? $name : 'Unknown member',
            $employee?->rank !== null ? trim((string) $employee->rank) : null,
            $context,
            $user,
        );
    }

    public static function member(User $user): self
    {
        return self::forUser($user, InventoryRequestEvent::CONTEXT_MEMBER);
    }

    /** Staff speak as Captain 5 when they qualify for it, otherwise as EMS Admin. */
    public static function staff(User $user, EmsAccess $access): self
    {
        return self::forUser($user, $access->staffContext($user));
    }

    public static function system(): self
    {
        return new self(null, 'MBFD Hub', null, InventoryRequestEvent::CONTEXT_SYSTEM);
    }

    public function isStaff(): bool
    {
        return in_array($this->context, [InventoryRequestEvent::CONTEXT_CAPTAIN5, InventoryRequestEvent::CONTEXT_EMS_ADMIN], true);
    }
}
