<?php

declare(strict_types=1);

namespace App\Services\Training\CrewPlanner;

use App\Models\Training\TrainingCourse;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class TrainingPlannerCandidateService
{
    public function __construct(private readonly TrainingPlannerAttendanceLookup $attendance) {}

    public function candidates(User $actor, TrainingCourse $course, ?int $moduleId, CarbonImmutable $asOf, array $effectiveStaffing, array $filters = [], ?string $personnelAsOf = null): array
    {
        $snapshot = $this->attendance->lookup($actor, $course, $moduleId, $asOf, $personnelAsOf);
        $filters = Validator::make($filters, [
            'rank' => ['nullable', 'string', 'max:100'], 'cohort' => ['nullable', Rule::in(['all', 'officers', 'captain', 'lieutenant', 'firefighters'])],
            'assignment' => ['nullable', Rule::in(['rescue', 'combat', 'day_other', 'unverified'])],
            'assignment_category' => ['nullable', Rule::in(['rescue', 'combat', 'day_other', 'unverified'])],
            'published_assignment' => ['nullable', 'string', 'max:255'],
            'de_bid' => ['nullable', 'boolean'], 'floating' => ['nullable', 'boolean'],
            'shift' => ['nullable', Rule::in(['A Shift', 'B Shift', 'C Shift', 'D Shift', 'Days'])],
            'search' => ['nullable', 'string', 'max:100'], 'day_station' => ['nullable', 'string', 'max:100'],
            'day_apparatus' => ['nullable', 'string', 'max:100'], 'day_seat_rank' => ['nullable', 'string', 'max:100'],
            'slot_start_at' => ['nullable', 'date', 'required_with:slot_end_at'],
            'slot_end_at' => ['nullable', 'date', 'required_with:slot_start_at', 'after:slot_start_at'],
        ])->validate();
        $identities = [];
        foreach ($snapshot['rows'] as $person) {
            // Prefix preserves exact string identity even for numeric-looking identifiers.
            $identities['id:'.$person['employee_id']][] = $person;
        }
        $rows = $review = [];
        foreach ($effectiveStaffing as $rawId => $duty) {
            $rawId = (string) ($duty['raw_employee_id'] ?? $rawId);
            $matches = $identities['id:'.$rawId] ?? [];
            if (count($matches) !== 1) {
                $review[] = ['raw_employee_id' => $rawId, 'raw_name' => (string) ($duty['raw_name'] ?? ''),
                    'attendance_status' => 'IDENTITY_OR_EVIDENCE_REVIEW', 'eligible_slots' => [],
                    'needs_review' => ['The employee ID has no unique active personnel match.']];

                continue;
            }
            $person = $matches[0];
            $row = $person + array_intersect_key($duty, array_flip(['working_intervals', 'unavailable_slots', 'partial_slots', 'observed_unit_context', 'review_intervals', 'global_review_reasons']));
            $row['needs_review'] = array_values($duty['needs_review'] ?? []);
            if (in_array($person['attendance_status'], ['IDENTITY_OR_EVIDENCE_REVIEW', 'UNVERIFIED_OR_PROVISIONAL'], true)) {
                $row['needs_review'][] = 'Prior attendance evidence requires verification.';
            }
            // Staffing review is time-scoped; the resolver has already excluded affected slots.
            $row['eligible_slots'] = $person['attendance_status'] === 'NO_PRIOR_ATTENDANCE'
                ? array_values($duty['eligible_slots'] ?? []) : [];
            $row['has_review'] = $person['has_review'] || $row['needs_review'] !== [];
            if ($this->matches($row, $filters)) {
                $rows[$person['employee_profile_id']] = $row;
            }
        }
        uasort($rows, fn (array $left, array $right): int => strnatcasecmp($left['name'], $right['name']) ?: $left['employee_profile_id'] <=> $right['employee_profile_id']);
        $snapshot['rows'] = $rows;
        $snapshot['review'] = $review;
        $snapshot['filters'] = $filters;
        $snapshot['totals'] = ['personnel' => count($rows), 'eligible' => count(array_filter($rows, fn (array $row): bool => $row['eligible_slots'] !== [])),
            'review' => count($review) + count(array_filter($rows, fn (array $row): bool => $row['has_review'] || $row['has_provisional'])),
            'already_attended' => count(array_filter($rows, fn (array $row): bool => $row['attendance_status'] === 'VERIFIED_ATTENDED'))];

        return $snapshot;
    }

    private function matches(array $row, array $filters): bool
    {
        $ranks = match ($filters['cohort'] ?? 'all') {
            'officers' => ['Captain', 'Lieutenant'], 'captain' => ['Captain'], 'lieutenant' => ['Lieutenant'],
            'firefighters' => ['Firefighter'], default => null,
        };
        if (($ranks !== null && ! in_array($row['rank'], $ranks, true)) || (filled($filters['rank'] ?? null) && $row['rank'] !== $filters['rank'])) {
            return false;
        }
        foreach (['assignment' => 'assignment_category', 'assignment_category' => 'assignment_category', 'published_assignment' => 'assignment', 'shift' => 'shift', 'de_bid' => 'is_de_bid', 'floating' => 'is_floating'] as $filter => $field) {
            if (! isset($filters[$filter]) || $filters[$filter] === '') {
                continue;
            }
            if (in_array($filter, ['de_bid', 'floating'], true) && ! $row['assignment_verified']) {
                return false;
            }
            $expected = in_array($filter, ['de_bid', 'floating'], true) ? (bool) $filters[$filter] : $filters[$filter];
            if ($row[$field] !== $expected) {
                return false;
            }
        }
        if (filled($filters['search'] ?? null) && ! str_contains(mb_strtolower($row['name'].' '.$row['employee_id']), mb_strtolower(trim($filters['search'])))) {
            return false;
        }
        $dayFilters = array_filter(array_intersect_key($filters, array_flip(['day_station', 'day_apparatus', 'day_seat_rank'])), fn ($value): bool => filled($value));
        if ($dayFilters !== []) {
            foreach ($row['observed_unit_context'] ?? [] as $context) {
                if (filled($filters['slot_start_at'] ?? null) && (! filled($context['start_at'] ?? null) || ! filled($context['end_at'] ?? null)
                    || CarbonImmutable::parse($context['start_at'])->greaterThanOrEqualTo(CarbonImmutable::parse($filters['slot_end_at']))
                    || CarbonImmutable::parse($context['end_at'])->lessThanOrEqualTo(CarbonImmutable::parse($filters['slot_start_at'])))) {
                    continue;
                }
                $matches = true;
                foreach (['day_station' => 'station', 'day_apparatus' => 'apparatus', 'day_seat_rank' => 'seat_rank'] as $filter => $field) {
                    if (isset($dayFilters[$filter]) && (string) ($context[$field] ?? '') !== $dayFilters[$filter]) {
                        $matches = false;
                    }
                }
                if ($matches) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }
}
