<?php

declare(strict_types=1);

namespace App\Services\Training\CrewPlanner;

use App\Models\Training\TrainingAttendance;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingModule;
use App\Models\Training\TrainingRosterVersion;
use App\Models\Training\TrainingSession;
use App\Models\User;
use App\Services\Training\TrainingPersonnelClassificationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Independent read model: never invokes roster lifecycle or matrix opt-in. */
final class TrainingPlannerAttendanceLookup
{
    public function __construct(private readonly TrainingPersonnelClassificationService $personnel) {}

    public function lookup(User $actor, TrainingCourse $course, ?int $moduleId, CarbonImmutable $asOf, ?string $personnelAsOf = null): array
    {
        Gate::forUser($actor)->authorize('create', TrainingCourse::class);
        Gate::forUser($actor)->authorize('view', $course);
        $course = TrainingCourse::query()->findOrFail($course->getKey());
        $personnelAsOf ??= $asOf->setTimezone(\App\Support\HubTimezone::NAME)->toDateString();
        Validator::make(['personnel_as_of' => $personnelAsOf], ['personnel_as_of' => ['required', 'date_format:Y-m-d']])->validate();
        $module = $moduleId === null ? null : TrainingModule::query()->where('training_course_id', $course->id)->find($moduleId);
        if ($moduleId !== null && $module === null) {
            throw ValidationException::withMessages(['module_id' => 'Choose a module belonging to this course.']);
        }
        // Unassigned sessions are inspected as review evidence for module scope, never counted as that module.
        $sessions = TrainingSession::query()->where('training_course_id', $course->id)
            ->when($moduleId !== null, fn ($query) => $query->where(fn ($scope) => $scope->where('training_module_id', $moduleId)->orWhereNull('training_module_id')))
            ->orderBy('held_on')->orderBy('starts_at')->orderBy('id')->get();
        $prior = $sessions->filter(fn (TrainingSession $session): bool => $session->held_on->toDateString() <= $asOf->setTimezone(\App\Support\HubTimezone::NAME)->toDateString()
            && ($session->starts_at === null || $session->starts_at->lessThanOrEqualTo($asOf)))->keyBy('id');
        $proofs = $this->archivedProofs($prior->all(), $asOf);
        $entries = $exceptions = [];
        foreach (TrainingAttendance::query()->whereIn('training_session_id', $prior->keys())->where('signed_at', '<=', $asOf->utc())->get() as $attendance) {
            $session = $prior[$attendance->training_session_id];
            $signature = $attendance->getAttribute('signature');
            $valid = $this->validSignature($signature);
            $proof = $proofs[$session->id][$attendance->id] ?? null;
            $finalized = in_array($session->status, ['submitted', 'certified'], true)
                && ($session->submitted_at === null || $session->submitted_at->lessThanOrEqualTo($asOf));
            $finalized = $finalized || ($session->status === 'archived' && $proof !== null && $valid
                && $proof['employee_profile_id'] === (int) $attendance->employee_profile_id
                && hash_equals($proof['signature_sha256'], $signature['sha256']));
            $status = ! $valid || $attendance->voided_at !== null || ($attendance->signature_signed_at?->greaterThan($asOf) ?? false)
                ? 'review' : ($finalized ? 'digital' : 'provisional');
            if ($moduleId !== null && $session->training_module_id === null) {
                $status = 'review';
            }
            $entries[(int) $attendance->employee_profile_id][] = ['source' => 'digital', 'status' => $status,
                'session_id' => (int) $session->id, 'evidence_id' => (int) $attendance->id];
        }
        $paper = DB::table('training_paper_attendances as a')->join('training_paper_roster_imports as i', 'i.id', '=', 'a.training_paper_roster_import_id')
            ->whereIn('a.training_session_id', $prior->keys())->whereColumn('a.training_session_id', 'i.training_session_id')
            ->select(['a.id', 'a.training_session_id', 'a.employee_profile_id', 'a.source_page', 'a.source_row', 'a.review_status',
                'a.evidence_present', 'a.reviewed_by', 'a.reviewed_at', 'i.source_sha256', 'i.page_count'])->get();
        foreach ($paper as $entry) {
            $session = $prior[$entry->training_session_id];
            $approved = $entry->review_status === 'approved' && $entry->employee_profile_id !== null && $entry->evidence_present
                && $entry->reviewed_by !== null && $entry->reviewed_at !== null
                && $this->atOrBefore($entry->reviewed_at, $asOf)
                && preg_match('/^[a-f0-9]{64}$/D', $entry->source_sha256)
                && $entry->source_page >= 1 && $entry->source_page <= $entry->page_count && filled($entry->source_row)
                && ($moduleId === null || $session->training_module_id === $moduleId);
            if ($entry->employee_profile_id === null) {
                $exceptions[] = ['type' => 'paper_identity_review', 'session_id' => (int) $session->id,
                    'employee_profile_id' => null, 'reason' => 'A paper attendance identity needs review.'];

                continue;
            }
            $entries[(int) $entry->employee_profile_id][] = ['source' => 'paper', 'status' => $approved ? 'paper' : 'review',
                'session_id' => (int) $session->id, 'evidence_id' => (int) $entry->id];
        }
        $rows = [];
        foreach ($this->personnel->population($personnelAsOf) as $person) {
            $evidence = $entries[$person['employee_profile_id']] ?? [];
            $statuses = array_column($evidence, 'status');
            $verified = in_array('digital', $statuses, true) || in_array('paper', $statuses, true);
            $review = in_array('review', $statuses, true);
            $provisional = in_array('provisional', $statuses, true);
            $applicable = $module === null ? true : $this->applicable($person, $module->audience ?? []);
            $status = $verified ? 'VERIFIED_ATTENDED' : ($review ? 'IDENTITY_OR_EVIDENCE_REVIEW'
                : ($provisional ? 'UNVERIFIED_OR_PROVISIONAL' : 'NO_PRIOR_ATTENDANCE'));
            if ($applicable !== true) {
                $status = $applicable === false ? 'NOT_APPLICABLE' : 'IDENTITY_OR_EVIDENCE_REVIEW';
                $review = $review || $applicable === null;
            }
            $rows[$person['employee_profile_id']] = $person + ['attendance_status' => $status,
                'has_review' => $review, 'has_provisional' => $provisional, 'applicable' => $applicable,
                'evidence' => $evidence, 'attended_session_ids' => array_values(array_unique(array_column(array_filter($evidence,
                    fn (array $item): bool => in_array($item['status'], ['digital', 'paper'], true)), 'session_id')))];
        }
        foreach (array_diff(array_keys($entries), array_keys($rows)) as $employeeId) {
            $exceptions[] = ['type' => 'outside_active_population', 'employee_profile_id' => (int) $employeeId,
                'reason' => 'Attendance exists outside the active personnel population.'];
        }

        return ['course' => $course->only(['id', 'name']), 'module' => $module?->only(['id', 'name']),
            'as_of' => $asOf->toIso8601String(), 'personnel_as_of' => $personnelAsOf, 'rows' => $rows, 'exceptions' => $exceptions,
            'session_suggestions' => $sessions->filter(fn (TrainingSession $session): bool => $session->held_on->toDateString() === $personnelAsOf
                && $session->starts_at !== null && ($moduleId === null || $session->training_module_id === $moduleId))
                ->map(fn (TrainingSession $session): array => ['id' => (int) $session->id, 'label' => 'Existing session '.$session->id,
                    'start_at' => $session->starts_at->toIso8601String(), 'end_at' => null])->values()->all()];
    }

    /** The active population for a class with no course behind it: there is no attendance history to check. */
    public function population(User $actor, string $personnelAsOf): array
    {
        Gate::forUser($actor)->authorize('create', TrainingCourse::class);
        $rows = [];
        foreach ($this->personnel->population($personnelAsOf) as $person) {
            $rows[$person['employee_profile_id']] = $person + ['attendance_status' => 'NO_PRIOR_ATTENDANCE',
                'has_review' => false, 'has_provisional' => false, 'applicable' => true, 'evidence' => [], 'attended_session_ids' => []];
        }

        return ['course' => null, 'module' => null, 'personnel_as_of' => $personnelAsOf, 'rows' => $rows, 'exceptions' => [], 'session_suggestions' => []];
    }

    private function applicable(array $person, array $audience): ?bool
    {
        $unknown = false;
        foreach (['ranks' => 'rank', 'shifts' => 'shift'] as $key => $field) {
            if (($audience[$key] ?? []) === []) {
                continue;
            }
            if (blank($person[$field])) {
                $unknown = true;
            } elseif (! in_array($person[$field], $audience[$key], true)) {
                return false;
            }
        }

        return $unknown ? null : true;
    }

    /** Parity with matrix archive proof; no signature art or paths leave this service. */
    private function archivedProofs(array $sessions, CarbonImmutable $asOf): array
    {
        $ids = array_keys(array_filter($sessions, fn (TrainingSession $session): bool => $session->status === 'archived'));
        if ($ids === []) {
            return [];
        }
        $versions = TrainingRosterVersion::query()->join('training_sessions as session', 'session.id', '=', 'training_roster_versions.training_session_id')
            ->whereIn('training_roster_versions.training_session_id', $ids)->whereColumn('training_roster_versions.revision', 'session.revision')
            ->select('training_roster_versions.*')->get();
        $proofs = [];
        foreach ($versions as $version) {
            $snapshot = $version->snapshot;
            if (($snapshot['session']['status'] ?? null) !== 'archived' || (int) ($snapshot['session']['id'] ?? 0) !== (int) $version->training_session_id
                || (int) ($snapshot['session']['revision'] ?? 0) !== $version->revision) {
                continue;
            }
            $certification = $snapshot['certification'] ?? [];
            $signoff = $snapshot['instructor_signoff'] ?? [];
            $submission = $snapshot['submission'] ?? [];
            $certified = $this->atOrBefore($certification['certified_at'] ?? null, $asOf) && (int) ($certification['certified_by'] ?? 0) > 0
                && (int) ($certification['oic_snapshot']['employee_profile_id'] ?? 0) > 0 && $this->validSignature($certification['oic_signature'] ?? null);
            $submitted = $this->atOrBefore($submission['submitted_at'] ?? null, $asOf) && (int) ($submission['submitted_by'] ?? 0) > 0
                && (int) ($signoff['recorded_by'] ?? 0) === (int) $submission['submitted_by']
                && (int) ($signoff['lead_snapshot']['employee_profile_id'] ?? 0) > 0
                && $this->atOrBefore($signoff['signed_at'] ?? null, $asOf) && $this->validSignature($signoff['signature'] ?? null);
            if (! $certified && ! $submitted) {
                continue;
            }
            foreach ($snapshot['attendance'] ?? [] as $entry) {
                if ($this->validSignature($entry['signature'] ?? null)) {
                    $proofs[$version->training_session_id][(int) $entry['id']] = ['employee_profile_id' => (int) $entry['employee_profile_id'],
                        'signature_sha256' => $entry['signature']['sha256']];
                }
            }
        }

        return $proofs;
    }

    private function atOrBefore(mixed $timestamp, CarbonImmutable $asOf): bool
    {
        if (! is_string($timestamp) || blank($timestamp)) {
            return false;
        }
        try {
            return CarbonImmutable::parse($timestamp, 'UTC')->lessThanOrEqualTo($asOf);
        } catch (\Exception) {
            return false;
        }
    }

    private function validSignature(mixed $signature): bool
    {
        return is_array($signature) && filled($signature['disk'] ?? null) && filled($signature['path'] ?? null)
            && in_array($signature['mime'] ?? null, ['image/png', 'image/jpeg'], true)
            && (bool) preg_match('/^[a-f0-9]{64}$/D', (string) ($signature['sha256'] ?? ''));
    }
}
