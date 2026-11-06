<?php

declare(strict_types=1);

namespace App\Services\Personnel\FuelIds;

use App\Models\FuelIdRow;

/** What an import would do. It holds PINs in memory only; summaries never include them. */
final readonly class FuelIdImportPlan
{
    /** @param list<FuelIdPlannedRow> $rows */
    public function __construct(
        public string $format,
        public string $sha256,
        public array $rows,
        public bool $alreadyImported,
    ) {}

    /** @return array<string, int> */
    public function counts(): array
    {
        $counts = array_fill_keys([FuelIdRow::MATCHED, FuelIdRow::NAME_MISMATCH, FuelIdRow::CONFLICT, FuelIdRow::UNMATCHED], 0);
        foreach ($this->rows as $planned) {
            $counts[$planned->status] = ($counts[$planned->status] ?? 0) + 1;
        }

        return $counts;
    }

    /** @return list<FuelIdPlannedRow> */
    public function exceptions(): array
    {
        return array_values(array_filter($this->rows, fn (FuelIdPlannedRow $row): bool => $row->isException()));
    }

    /** @return list<FuelIdPlannedRow> */
    public function departedMatches(): array
    {
        return array_values(array_filter($this->rows, fn (FuelIdPlannedRow $row): bool => $row->status === FuelIdRow::MATCHED && $row->employeeDeparted));
    }

    /**
     * A summary safe to show to anyone allowed to run an import: counts and exception identities, PIN masked.
     *
     * @return array{format: string, already_imported: bool, total: int, counts: array<string, int>, exceptions: list<array<string, string|int>>, departed: list<array<string, string>>}
     */
    public function summary(): array
    {
        return [
            'format' => $this->format,
            'already_imported' => $this->alreadyImported,
            'total' => count($this->rows),
            'counts' => $this->counts(),
            'exceptions' => array_map(fn (FuelIdPlannedRow $planned): array => [
                'row' => $planned->row->rowNumber,
                'employee_number' => $planned->row->employeeNumber,
                'name' => $planned->row->displayName(),
                'status' => $planned->status,
                'reason' => $planned->reason,
                'pin' => FuelPinView::MASK,
            ], $this->exceptions()),
            'departed' => array_map(fn (FuelIdPlannedRow $planned): array => [
                'employee_number' => $planned->row->employeeNumber,
                'name' => (string) $planned->employeeName,
            ], $this->departedMatches()),
        ];
    }
}
