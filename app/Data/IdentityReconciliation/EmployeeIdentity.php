<?php

declare(strict_types=1);

namespace App\Data\IdentityReconciliation;

final readonly class EmployeeIdentity
{
    /** @param list<array<string, int|string|null>> $externalMappings */
    public function __construct(
        public int $id,
        public string $employeeId,
        public string $name,
        public ?string $rank,
        public ?string $passwordHash,
        public bool $mustChangePassword,
        public array $externalMappings,
    ) {}
}
