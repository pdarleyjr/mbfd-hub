<?php

declare(strict_types=1);

namespace App\Services\Personnel\FuelIds;

use App\Models\FuelIdRow;

/** A source row with its matching decision. */
final readonly class FuelIdPlannedRow
{
    public function __construct(
        public FuelIdSourceRow $row,
        public string $status,
        public string $reason,
        public ?int $employeeId = null,
        public ?int $candidateEmployeeId = null,
        public ?string $employeeName = null,
        public bool $employeeDeparted = false,
    ) {}

    public function isException(): bool
    {
        return in_array($this->status, FuelIdRow::OPEN_EXCEPTIONS, true);
    }
}
