<?php

declare(strict_types=1);

namespace App\Services\Captain5;

use App\Models\EmployeeBidAssignment;
use Illuminate\Support\Collection;

/**
 * Current, integrity-checked bid seats (same verification rule as
 * Command300Access::hasVerifiedCurrentAssignment): payload v2, not superseded, latest
 * published bid year, valid hashes, matching term label and an award/retained source.
 * A member counts only through a single current row.
 */
final class VerifiedBidSeats
{
    /** @var array<string, Collection<int, EmployeeBidAssignment>> */
    private array $byUnit = [];

    /** @return Collection<int, EmployeeBidAssignment> keyed by employee_profile_id */
    public function holders(string $unitLabel): Collection
    {
        if (isset($this->byUnit[$unitLabel])) {
            return $this->byUnit[$unitLabel];
        }

        $current = EmployeeBidAssignment::query()
            ->where('payload_version', 2)
            ->whereNull('superseded_at')
            ->where('bid_year', fn ($latest) => $latest->from('employee_bid_assignments')->selectRaw('max(bid_year)')
                ->where('payload_version', 2)->whereNull('superseded_at'));

        // Members with more than one current row are an integrity problem: trust neither row.
        $ambiguous = (clone $current)->select('employee_profile_id')
            ->groupBy('employee_profile_id')->havingRaw('count(*) > 1')->pluck('employee_profile_id')
            ->map(fn ($id): int => (int) $id)->all();

        return $this->byUnit[$unitLabel] = $current
            ->where('unit_label', $unitLabel)
            ->orderBy('id')
            ->get()
            ->reject(fn (EmployeeBidAssignment $row): bool => in_array((int) $row->employee_profile_id, $ambiguous, true))
            ->filter(fn (EmployeeBidAssignment $row): bool => $this->verified($row) && trim((string) $row->unit_label) === $unitLabel)
            ->keyBy(fn (EmployeeBidAssignment $row): int => (int) $row->employee_profile_id);
    }

    public function seatOf(int $employeeProfileId, string $unitLabel): ?EmployeeBidAssignment
    {
        return $this->holders($unitLabel)->get($employeeProfileId);
    }

    /** Forget memoized seats (tests and long-running workers). */
    public function flush(): void
    {
        $this->byUnit = [];
    }

    private function verified(EmployeeBidAssignment $row): bool
    {
        foreach (['source_result_hash', 'source_workbook_sha256', 'payload_hash'] as $hash) {
            if (! preg_match('/^[a-f0-9]{64}$/D', (string) $row->getAttribute($hash))) {
                return false;
            }
        }

        return $row->term_label === $row->bid_year.'–'.($row->bid_year + 1)
            && in_array($row->assignment_source, ['bid_award', 'retained_nonbiddable'], true);
    }
}
