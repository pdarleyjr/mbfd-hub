<?php

declare(strict_types=1);

namespace App\Services\Training;

use App\Models\Employee;
use App\Models\EmployeeBidAssignment;
use App\Services\PersonnelRequests\UniformEntitlementService;
use Carbon\CarbonImmutable;

final class TrainingPersonnelClassificationService
{
    public function __construct(private readonly UniformEntitlementService $uniforms) {}

    /** Local published projection only; historical sign-in snapshots remain untouched. */
    public function population(string $asOf): array
    {
        $boundary = CarbonImmutable::parse($asOf, 'America/New_York')->addDay()->startOfDay()->utc();
        $published = EmployeeBidAssignment::query()->where('payload_version', 2)->where('created_at', '<', $boundary)
            ->where(fn ($query) => $query->whereNull('superseded_at')->orWhere('superseded_at', '>=', $boundary));
        $year = (clone $published)->max('bid_year');
        $assignments = $year === null ? collect() : $published->where('bid_year', $year)->get()->groupBy('employee_profile_id');

        return Employee::query()->where('roster_status', 'active')->orderBy('name')->orderBy('id')->get()
            ->map(function (Employee $employee) use ($assignments, $asOf): array {
                $matches = $assignments->get($employee->id, collect());
                $assignment = $matches->count() === 1 ? $matches->first() : null;
                $verified = $assignment !== null && $this->valid($assignment);
                $fields = $verified ? $assignment->getAttributes() : [];
                $classification = $this->uniforms->classify($fields);
                $rank = trim((string) $employee->rank);
                $rank = match (mb_strtolower($rank)) {
                    'captain' => 'Captain', 'lieutenant' => 'Lieutenant', 'firefighter' => 'Firefighter', default => $rank,
                };

                return [
                    'employee_profile_id' => (int) $employee->id, 'employee_id' => (string) $employee->employee_id,
                    'name' => (string) $employee->name, 'rank' => $rank,
                    'shift' => $fields['shift_label'] ?? null,
                    'assignment' => $fields['bid_selection_label'] ?? 'Assignment unverified',
                    'assignment_category' => $verified ? $classification['profile'] : 'unverified',
                    'assignment_verified' => $verified,
                    // The actual published seat is "Firefighter DE #2", not the rank or a certification.
                    'is_de_bid' => $verified && $rank === 'Firefighter' && $fields['assignment_source'] === 'bid_award'
                        && (bool) preg_match('/^(?:Firefighter\s+)?(?:DE|Driver\s+Engineer)(?:\s*#\s*\d+)?$/iD', trim((string) ($fields['position_label'] ?? ''))),
                    'is_floating' => $verified && ($fields['assignment_type'] ?? null) === 'Floating',
                    'term_label' => $fields['term_label'] ?? null, 'bid_year' => $fields['bid_year'] ?? null,
                    'assignment_as_of' => $asOf,
                ];
            })->all();
    }

    private function valid(EmployeeBidAssignment $assignment): bool
    {
        $fields = $assignment->getAttributes();
        foreach (['source_result_hash', 'source_workbook_sha256', 'payload_hash'] as $hash) {
            if (! preg_match('/^[a-f0-9]{64}$/D', (string) ($fields[$hash] ?? ''))) {
                return false;
            }
        }

        return $assignment->term_label === $assignment->bid_year.'–'.($assignment->bid_year + 1)
            && in_array($assignment->shift_label, ['A Shift', 'B Shift', 'C Shift', 'D Shift', 'Days'], true)
            && in_array($assignment->assignment_source, ['bid_award', 'retained_nonbiddable'], true)
            && $assignment->source_sequence !== null && filled($assignment->bid_session_id);
    }
}
