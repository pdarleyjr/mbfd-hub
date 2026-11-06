<?php

declare(strict_types=1);

namespace App\Services\Personnel\FuelIds;

/** One validated row from a fuel list, before matching. All values are strings so leading zeros survive. */
final readonly class FuelIdSourceRow
{
    public function __construct(
        public int $rowNumber,
        public ?int $page,
        public string $employeeNumber,
        public string $lastName,
        public string $firstName,
        public string $promptId,
        public ?string $status,
        public ?string $department,
    ) {}

    public function normalizedEmployeeNumber(): string
    {
        return FuelIdNameMatcher::normalizeEmployeeNumber($this->employeeNumber);
    }

    public function displayName(): string
    {
        return trim(implode(', ', array_filter([$this->lastName, $this->firstName], fn (string $part): bool => $part !== '')));
    }
}
