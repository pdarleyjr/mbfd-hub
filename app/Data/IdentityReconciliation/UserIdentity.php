<?php

declare(strict_types=1);

namespace App\Data\IdentityReconciliation;

final readonly class UserIdentity
{
    /**
     * @param  list<string>  $roles
     * @param  list<string>  $directPermissions
     * @param  list<string>  $effectivePermissions
     * @param  list<array{id: int, name: string, role: string, active: bool}>  $workgroups
     * @param  array<string, int>  $notificationRelationships
     * @param  list<array<string, int|string|null>>  $externalMappings
     */
    public function __construct(
        public int $id,
        public ?string $legacyEmployeeId,
        public ?int $employeeProfileId,
        public ?string $accountStatus,
        public int $securityVersion,
        public string $name,
        public string $email,
        public ?string $rank,
        public ?string $passwordHash,
        public bool $mustChangePassword,
        public array $roles,
        public array $directPermissions,
        public array $effectivePermissions,
        public array $workgroups,
        public array $notificationRelationships,
        public array $externalMappings,
    ) {}
}
