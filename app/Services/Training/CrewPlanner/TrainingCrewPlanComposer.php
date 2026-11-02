<?php

declare(strict_types=1);

namespace App\Services\Training\CrewPlanner;

use App\Models\Training\TrainingCourse;
use App\Models\User;
use App\Policies\TrainingRosterPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Turns a saved proposal into who is available and what would go out. Every read, export and publish comes
 * through here, so each one re-checks the roster, attendance and permission on the server at that moment.
 * With no saved session a proposal is a simple list; once one is saved, session rules apply.
 */
final class TrainingCrewPlanComposer
{
    private const TZ = \App\Support\HubTimezone::NAME;

    public function __construct(
        private readonly TrainingRosterDutySource $roster,
        private readonly TeleStaffDutyResolver $resolver,
        private readonly TrainingPlannerCandidateService $candidates,
        private readonly TrainingCrewPlannerDutyContext $context,
        private readonly TrainingRosterPolicy $policy,
    ) {}

    public function duty(array $draft): TrainingRosterDuty
    {
        return $this->roster->forDate($this->date($draft));
    }

    public function date(array $draft): string
    {
        $date = $draft['metadata']['schedule_date'] ?? null;

        return is_string($date) && $date !== '' ? $date : $this->roster->today();
    }

    public function course(User $actor, ?int $id): ?TrainingCourse
    {
        if ($id === null) {
            return null;
        }
        $course = TrainingCourse::query()->findOrFail($id);
        abort_unless($this->policy->view($actor, $course), 403);

        return $course;
    }

    public function hasClass(array $draft): bool
    {
        return ! empty($draft['course_id']) || trim((string) ($draft['class_title'] ?? '')) !== '';
    }

    public function analyze(User $actor, array $draft, array $filters = [], ?TrainingRosterDuty $duty = null): array
    {
        if (! $this->hasClass($draft)) {
            return ['rows' => [], 'review' => [], 'session_suggestions' => []];
        }
        $duty ??= $this->duty($draft);
        $staff = $this->resolver->resolveStaff($duty->staff, $draft['slots']);

        return $this->candidates->candidates($actor, $this->course($actor, $draft['course_id'] ?? null), $draft['module_id'] ?? null,
            $this->attendanceCutoff($draft)[0], $staff, $filters, $this->date($draft), $duty->isVerified(), array_column($draft['slots'], 'id'));
    }

    /**
     * Attendance is judged as it stood when today's class begins: the earliest saved session start when
     * that is already past, otherwise the present moment. An older roster never moves the cutoff back.
     *
     * @return array{0: CarbonImmutable, 1: bool}
     */
    public function attendanceCutoff(array $draft): array
    {
        $now = CarbonImmutable::now();
        if ($draft['slots'] === [] || $this->date($draft) !== $now->setTimezone(self::TZ)->toDateString()) {
            return [$now, false];
        }
        $earliest = collect($draft['slots'])->map(fn (array $slot): CarbonImmutable => CarbonImmutable::parse($slot['start_at']))->sortBy(fn (CarbonImmutable $start): int => $start->getTimestamp())->first();

        return $earliest !== null && $earliest->lt($now) ? [$earliest, true] : [$now, false];
    }

    public function eligible(array $row, array $draft): bool
    {
        return $draft['slots'] === [] ? ($row['available'] ?? false) : ! empty($row['eligible_slots']);
    }

    public function needsVerification(?array $person, array $selection, array $draft): bool
    {
        if (! $person) {
            return true;
        }
        if (! empty($selection['exception'])) {
            return false;
        }

        return ! $this->eligible($person, $draft)
            || (($selection['slot_id'] ?? null) !== null && ! in_array($selection['slot_id'], $person['eligible_slots'] ?? [], true));
    }

    /**
     * The roster moved on since this plan last looked at it. Only members on the plan whose duty actually
     * changed matter; if there are none the newer roster is simply adopted.
     *
     * @return array{stale: bool, material: bool, members: list<string>, as_of: ?CarbonImmutable}
     */
    public function rosterChange(User $actor, array $draft, TrainingRosterDuty $duty): array
    {
        $acknowledged = $draft['metadata']['acknowledged_snapshot_id'] ?? null;
        if ($acknowledged === null || $acknowledged === $duty->snapshotId || $draft['selections'] === []) {
            return ['stale' => false, 'material' => false, 'members' => [], 'as_of' => $duty->asOf];
        }
        $before = $this->roster->forSnapshotId((int) $acknowledged);
        $rows = $this->analyze($actor, $draft, [], $duty)['rows'] ?? [];
        $members = [];
        foreach ($draft['selections'] as $id => $selection) {
            $person = $rows[$id] ?? null;
            if ($person === null) {
                continue;
            }
            $was = $before?->staff[$person['employee_id']]['working_intervals'] ?? [];
            $now = $duty->staff[$person['employee_id']]['working_intervals'] ?? [];
            if ($this->normalizeIntervals($was) !== $this->normalizeIntervals($now)) {
                $members[] = (string) $person['name'];
            }
        }

        return ['stale' => true, 'material' => $members !== [] || ! $duty->isVerified(), 'members' => $members, 'as_of' => $duty->asOf];
    }

    /**
     * The revalidated plan both exports and all publishing use. Nothing is taken from the browser.
     *
     * @throws ValidationException when the plan cannot go out as it stands
     */
    public function projection(User $actor, array $draft, bool $reviewConfirmed): array
    {
        abort_unless($this->policy->create($actor), 403);
        if (! $this->hasClass($draft)) {
            throw ValidationException::withMessages(['plan' => 'Choose a course or enter a class title first.']);
        }
        if ($draft['selections'] === []) {
            throw ValidationException::withMessages(['plan' => 'Add at least one member first.']);
        }
        $duty = $this->duty($draft);
        if ($this->rosterChange($actor, $draft, $duty)['material']) {
            throw ValidationException::withMessages(['plan' => 'The roster changed for members on this plan. Review the changes and acknowledge them first. Your list is safe.']);
        }
        $analysis = $this->analyze($actor, $draft, [], $duty);
        $course = $this->course($actor, $draft['course_id'] ?? null);
        $rows = [];
        foreach ($draft['selections'] as $id => $selection) {
            $person = $analysis['rows'][$id] ?? null;
            $review = $this->needsVerification($person, $selection, $draft);
            if ($review && ! $reviewConfirmed) {
                throw ValidationException::withMessages(['confirmReview' => 'Some members need fresh verification. Review them under "Needs verification" and confirm first.']);
            }
            $slot = collect($draft['slots'])->firstWhere('id', $selection['slot_id'] ?? null);
            $place = $this->context->project($person['observed_unit_context'] ?? [], $slot);
            $rows[$id] = [
                'employee_id' => $person['employee_id'] ?? '', 'name' => $person['name'] ?? 'Previously selected member — identity requires review',
                'rank' => $person['rank'] ?? '', 'bid_position' => ($person['assignment'] ?? '').(! empty($person['is_de_bid']) ? ' · Published Driver Engineer seat' : ''),
                'station' => $place['station_plain'], 'apparatus' => $place['apparatus_plain'],
                'slot_id' => $selection['slot_id'] ?? null, 'verification_required' => $review,
                'exception' => ! empty($selection['exception']),
                'review_reasons' => $review ? ['Recheck identity, current attendance and full class-time availability before approval.'] : [],
            ];
        }

        return [
            'course_name' => $course !== null ? $course->name : trim((string) $draft['class_title']), 'module_name' => $analysis['module']['name'] ?? null,
            'scope_label' => $course === null ? 'Class' : ($draft['module_id'] ? 'Selected module — no verified prior attendance' : 'Entire course — no verified prior attendance'),
            'schedule_date' => $duty->dutyDate, 'shift' => $duty->shift, 'shift_label' => $duty->shiftLabel(),
            'roster_verified' => $duty->isVerified(), 'roster_as_of' => $duty->asOf?->toIso8601String(), 'roster_version' => $duty->version,
            'generated_at' => now()->toIso8601String(), 'author' => $actor->name,
            'slots' => $draft['slots'], 'rows' => $rows,
        ];
    }

    private function normalizeIntervals(array $intervals): array
    {
        return array_map(static fn (array $interval): array => [
            CarbonImmutable::parse($interval['start_at'])->getTimestamp(), CarbonImmutable::parse($interval['end_at'])->getTimestamp(),
        ], $intervals);
    }
}
