<?php

declare(strict_types=1);

namespace App\Services\Training;

use App\Models\Employee;
use App\Models\Training\TrainingAttendance;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingModule;
use App\Models\Training\TrainingRosterAudit;
use App\Models\Training\TrainingRosterVersion;
use App\Models\Training\TrainingSession;
use App\Models\User;
use App\Services\PersonnelRequests\SignatureImageService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TrainingRosterService
{
    public function __construct(private TrainingAssignmentProvider $assignments, private SignatureImageService $signatures, private TrainingCourseModuleService $modules) {}

    public function createCourse(array $data, User $actor): TrainingCourse
    {
        $this->authorize($actor, 'create', TrainingSession::class);
        $attributes = $this->courseData($data);

        return DB::transaction(function () use ($attributes, $actor): TrainingCourse {
            $course = TrainingCourse::query()->create($attributes + ['created_by' => $actor->id]);
            $this->audit(null, 'course.created', $actor, null, null, $course->toArray());

            return $course;
        });
    }

    public function updateCourse(TrainingCourse $course, array $data, User $actor): TrainingCourse
    {
        $this->authorize($actor, 'create', TrainingSession::class);
        $attributes = $this->courseData($data);

        return DB::transaction(function () use ($course, $attributes, $actor): TrainingCourse {
            $course = TrainingCourse::query()->lockForUpdate()->findOrFail($course->id);
            $before = $course->toArray();
            $course->update($attributes);
            $this->audit(null, 'course.updated', $actor, null, $before, $course->toArray());

            return $course;
        });
    }

    public function createSession(TrainingCourse $course, array $data, User $actor): TrainingSession
    {
        $this->authorize($actor, 'create', TrainingSession::class);
        $attributes = $this->sessionData($data);

        return DB::transaction(function () use ($course, $attributes, $actor): TrainingSession {
            $course = TrainingCourse::query()->lockForUpdate()->findOrFail($course->id);
            $this->validateModule($course, $attributes['training_module_id'] ?? null);
            $session = TrainingSession::query()->create($attributes + [
                'public_id' => (string) Str::ulid(), 'training_course_id' => $course->id,
                'course_name' => $course->name, 'classifications' => $course->classifications,
                'location' => $course->default_location, 'status' => 'draft', 'revision' => 1, 'created_by' => $actor->id,
            ]);
            $this->audit($session, 'session.created', $actor, null, null, $session->toArray());
            $this->capture($session);

            return $session;
        });
    }

    public function startPublicModuleSession(TrainingCourse $course, string $name, User $actor): TrainingSession
    {
        return DB::transaction(function () use ($course, $name, $actor): TrainingSession {
            $module = $this->modules->findOrCreateModule($course, $name, $actor);

            return $this->startPublicSession($course, null, (int) $module->id);
        });
    }

    /** Reuse today's open delivery, never attendance from a completed or earlier class. */
    public function startPublicSession(TrainingCourse $course, ?string $sessionPublicId = null, ?int $moduleId = null): TrainingSession
    {
        return DB::transaction(function () use ($course, $sessionPublicId, $moduleId): TrainingSession {
            $course = TrainingCourse::query()->lockForUpdate()->findOrFail($course->id);
            $today = now(\App\Support\HubTimezone::NAME)->toDateString();
            $this->validateModule($course, $moduleId);
            if ($sessionPublicId !== null) {
                $session = $course->sessions()->where('public_id', $sessionPublicId)->lockForUpdate()->first();
                if ($session === null || $session->isPaper() || $session->status !== 'open'
                    || $session->held_on->toDateString() !== $today
                    || ($moduleId !== null && (int) $session->training_module_id !== $moduleId)) {
                    $this->reject('session', 'This roster is unavailable for this course and module today. Refresh the class list and choose an open roster.');
                }

                return $session;
            }
            if ($moduleId === null && $course->modules()->exists()) {
                $this->reject('training_module_id', 'Choose an existing module or add a new module to start today’s roster.');
            }
            $todaySessions = $course->sessions()->whereDate('held_on', $today)
                ->where('training_module_id', $moduleId)->where('status', 'open')->where('attendance_source', 'digital')
                ->orderBy('id')->limit(2)->get();
            if ($todaySessions->count() > 1) {
                $this->reject('session', 'More than one roster is open for this module or class today. Choose the matching time and location from the open rosters below.');
            }
            $session = $todaySessions->first();
            if ($session !== null) {
                return $session;
            }
            $session = $course->sessions()->create([
                'public_id' => (string) Str::ulid(), 'course_name' => $course->name,
                'training_module_id' => $moduleId,
                'classifications' => $course->classifications, 'location' => $course->default_location,
                'held_on' => $today, 'starts_at' => now()->utc(), 'status' => 'open', 'revision' => 1,
                'created_by' => null,
            ]);
            $this->audit($session, 'session.public_started', null, null, null, $session->toArray());
            $this->capture($session);

            return $session;
        });
    }

    public function submit(TrainingSession $session, User $actor, int $expectedRevision, string $submissionId, int $kioskGrantId, array $leadSignoff = [], bool $recoveryOnly = false): TrainingSession
    {
        abort_unless($actor->canSubmitTrainingRosters(), 403);
        Validator::make(['submission_id' => $submissionId], ['submission_id' => ['required', 'uuid']])->validate();
        if ($recoveryOnly && $leadSignoff !== []) {
            $this->reject('recovery_only', 'A receipt check cannot replace the recorded lead or signature.');
        }

        return DB::transaction(function () use ($session, $actor, $expectedRevision, $submissionId, $kioskGrantId, $leadSignoff, $recoveryOnly): TrainingSession {
            $session = $this->locked($session);
            $freshActor = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($freshActor->canSubmitTrainingRosters()
                && hash_equals($freshActor->getAuthPassword(), $actor->getAuthPassword())
                && $freshActor->security_version === $actor->security_version, 403);
            abort_unless($session->kioskGrants()->whereKey($kioskGrantId)->whereNull('revoked_at')->where('expires_at', '>', now())->exists(), 403, 'This screen authorization has ended. Return to classes.');
            if ($session->submission_id === $submissionId && $session->submitted_at !== null) {
                abort_unless((int) $session->submitted_by === (int) $actor->id, 403);
                $saved = $session->versions()->where('revision', $session->revision)->firstOrFail()->snapshot['instructor_signoff'] ?? null;
                if (! is_array($saved)) {
                    $this->reject('needs_signoff', 'This roster has no recorded lead signature. Ask training staff to reopen and complete it.');
                }
                if (! $recoveryOnly && ! hash_equals((string) ($saved['request_hash'] ?? ''), $this->signoffRequestHash($session, $actor, $expectedRevision, $this->leadSignoffData($leadSignoff)))) {
                    $this->reject('client_submission_id', 'This submission identifier was already used for a different lead or signature. The recorded signoff has not changed.');
                }

                return $session;
            }
            if ($recoveryOnly) {
                abort_unless($session->status === 'open', 409, 'This roster is no longer open and this request was not recorded. Return to classes.');
                $this->reject('needs_signoff', 'This request was not recorded. Select the lead instructor and collect their signature before submitting.');
            }
            Validator::make(['submission_id' => $submissionId], ['submission_id' => [Rule::unique('training_sessions', 'submission_id')->ignore($session->id)]])->validate();
            $this->editable($session, ['open']);
            $this->expectedRevision($session, $expectedRevision);
            if (! $session->attendances()->whereNull('voided_at')->exists()) {
                $this->reject('attendance', 'At least one member must sign before you submit this roster.');
            }
            $data = $this->leadSignoffData($leadSignoff);
            $lead = Employee::query()->where('roster_status', 'active')->lockForUpdate()->find($data['lead_employee_profile_id']);
            if ($lead === null || blank($lead->employee_id) || blank($lead->name)
                || Employee::query()->where('employee_id', $lead->employee_id)->count() !== 1) {
                $this->reject('lead_employee_profile_id', 'Select an active lead instructor with a unique personnel identity.');
            }
            try {
                $signature = $this->signatures->store($data['lead_signature'], 'training-rosters');
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages(['lead_signature' => $exception->errors()['signature'] ?? ['Please provide a valid lead instructor signature.']]);
            }
            $signoff = [
                'lead_snapshot' => ['employee_profile_id' => $lead->id, 'employee_id' => (string) $lead->employee_id, 'display_name' => (string) $lead->name],
                'signature' => $signature,
                'signed_at' => now()->utc()->toIso8601String(), 'recorded_by' => $actor->id,
                'request_hash' => $this->signoffRequestHash($session, $actor, $expectedRevision, $data),
            ];
            $before = $session->toArray();
            $session->update([
                'status' => 'submitted', 'submitted_at' => now()->utc(), 'submitted_by' => $actor->id,
                'submission_id' => $submissionId, 'revision' => $session->revision + 1, 'oic_employee_profile_id' => $lead->id,
            ]);
            $version = $this->capture($session, instructorSignoff: $signoff);
            $this->audit($session, 'session.submitted', $actor, null, $before, $session->toArray() + ['version_id' => $version->id, 'instructor_signoff' => $signoff]);

            return $session;
        });
    }

    public function updateSession(TrainingSession $session, array $data, User $actor): TrainingSession
    {
        $this->authorize($actor, 'update', $session);
        $attributes = $this->sessionData($data);

        return DB::transaction(function () use ($session, $attributes, $actor, $data): TrainingSession {
            $session = $this->locked($session);
            $this->expectedRevision($session, isset($data['expected_revision']) ? (int) $data['expected_revision'] : null);
            if (array_key_exists('training_module_id', $attributes) && $attributes['training_module_id'] !== $session->training_module_id) {
                $this->reject('training_module_id', 'Use the audited module mapping action to change this roster’s module.');
            }
            $this->editable($session, ['draft', 'open', 'closed']);
            $before = $session->toArray();
            $session->update($attributes + ['revision' => $session->revision + 1]);
            $this->audit($session, 'session.updated', $actor, null, $before, $session->toArray());
            $this->capture($session);

            return $session;
        });
    }

    public function transition(TrainingSession $session, string $status, User $actor, ?string $reason = null, ?int $expectedRevision = null): TrainingSession
    {
        return DB::transaction(function () use ($session, $status, $actor, $reason, $expectedRevision): TrainingSession {
            $session = $this->locked($session);
            $this->expectedRevision($session, $expectedRevision);
            $ability = in_array($session->status, ['certified', 'submitted'], true) && $status === 'closed' ? 'reopen'
                : match ($status) {
                    'open' => 'open', 'closed' => 'close', 'archived' => 'archive', default => 'update'
                };
            $this->authorize($actor, $ability, $session);
            $allowed = ['draft' => ['open'], 'open' => ['closed'], 'closed' => ['open', 'archived'], 'submitted' => ['closed', 'archived'], 'certified' => ['closed', 'archived'], 'archived' => []];
            if (! in_array($status, $allowed[$session->status] ?? [], true)) {
                $this->reject('status', 'This roster cannot make that status change.');
            }
            if (in_array($session->status, ['certified', 'submitted'], true) && $status === 'closed') {
                $reason = $this->reason($reason);
            }
            $before = $session->toArray();
            $priorSnapshot = $session->versions()->where('revision', $session->revision)->first()?->snapshot;
            $certification = $status === 'archived' ? ($priorSnapshot['certification'] ?? null) : null;
            $signoff = $status === 'archived' ? ($priorSnapshot['instructor_signoff'] ?? null) : null;
            $session->update(['status' => $status, 'revision' => $session->revision + 1]
                + ($status === 'closed' ? ['submitted_at' => null, 'submitted_by' => null, 'submission_id' => null] : []));
            $this->audit($session, 'session.'.$status, $actor, null, $before, $session->toArray(), $reason);
            $this->capture($session, $certification, $signoff);

            return $session;
        });
    }

    public function sign(TrainingSession $session, int $employeeProfileId, string $signature, string $submissionId, ?int $kioskGrantId = null): TrainingAttendance
    {
        Validator::make(['submission_id' => $submissionId], ['submission_id' => ['required', 'uuid']])->validate();
        $hash = $this->requestHash($session->id, $employeeProfileId, $signature);

        return DB::transaction(function () use ($session, $employeeProfileId, $signature, $submissionId, $hash, $kioskGrantId): TrainingAttendance {
            $session = $this->locked($session);
            if ($kioskGrantId !== null && ! $session->kioskGrants()->whereKey($kioskGrantId)
                ->whereNull('revoked_at')->where('expires_at', '>', now())->exists()) {
                $this->reject('kiosk', 'This kiosk authorization has expired or been revoked. Ask the instructor to reconnect it.');
            }
            // A lost response may be recovered after closing; never write a second attendance.
            $recorded = $session->attendances()->where('client_submission_id', $submissionId)->first();
            if ($recorded !== null) {
                if (! hash_equals($recorded->request_hash, $hash)) {
                    $this->reject('submission_id', 'This submission identifier was already used for different data.');
                }
                if (TrainingRosterAudit::query()->where('training_attendance_id', $recorded->id)
                    ->whereIn('action', ['attendance.corrected', 'attendance.resigned'])->exists()) {
                    $this->reject('submission_id', 'The instructor has corrected this attendance. Ask the instructor to review its current status.');
                }

                return $recorded;
            }
            $this->editable($session, ['open']);
            if ($session->attendances()->where('employee_profile_id', $employeeProfileId)->exists()) {
                $this->reject('employee_profile_id', 'Attendance is already recorded. Ask the instructor to correct it if necessary.');
            }
            $snapshot = $this->candidate($employeeProfileId);
            $attendance = $session->attendances()->create([
                'employee_profile_id' => $employeeProfileId, 'snapshot' => $snapshot,
                'signature' => $this->signatures->store($signature, 'training-rosters'),
                'signed_at' => now()->utc(), 'signature_signed_at' => now()->utc(), 'client_submission_id' => $submissionId, 'request_hash' => $hash,
            ]);
            $this->audit($session, 'attendance.signed', null, $attendance, null, $attendance->toArray() + ['kiosk_grant_id' => $kioskGrantId]);
            $this->advance($session);

            return $attendance;
        });
    }

    public function void(TrainingAttendance $attendance, User $actor, string $reason, ?int $expectedRevision = null): void
    {
        $this->authorize($actor, 'correct', $attendance->session);
        $reason = $this->reason($reason);
        DB::transaction(function () use ($attendance, $actor, $reason, $expectedRevision): void {
            $session = $this->locked($attendance->session);
            $this->expectedRevision($session, $expectedRevision);
            $this->editable($session, ['open', 'closed']);
            $attendance = $session->attendances()->findOrFail($attendance->id);
            if ($attendance->voided_at !== null) {
                $this->reject('attendance', 'This attendance is already voided.');
            }
            $before = $attendance->toArray();
            $attendance->update(['voided_at' => now()->utc()]);
            $this->audit($session, 'attendance.voided', $actor, $attendance, $before, $attendance->toArray(), $reason);
            $this->advance($session);
        });
    }

    public function correct(TrainingAttendance $attendance, array $data, User $actor, string $reason): TrainingAttendance
    {
        $this->authorize($actor, 'correct', $attendance->session);
        $reason = $this->reason($reason);
        $data = Validator::make($data, ['employee_profile_id' => ['required', 'integer', 'min:1'], 'signature' => ['required', 'string', 'max:1400000'], 'expected_revision' => ['sometimes', 'integer', 'min:1']])->validate();

        return DB::transaction(function () use ($attendance, $data, $actor, $reason): TrainingAttendance {
            $session = $this->locked($attendance->session);
            $this->expectedRevision($session, isset($data['expected_revision']) ? (int) $data['expected_revision'] : null);
            $this->editable($session, ['open', 'closed']);
            $attendance = $session->attendances()->findOrFail($attendance->id);
            if ($session->attendances()->where('employee_profile_id', $data['employee_profile_id'])->whereKeyNot($attendance->id)->exists()) {
                $this->reject('employee_profile_id', 'This employee already has an attendance record.');
            }
            $before = $attendance->toArray();
            $snapshot = (int) $attendance->employee_profile_id === (int) $data['employee_profile_id']
                ? $attendance->snapshot
                : $this->candidate((int) $data['employee_profile_id'], $attendance->signed_at->setTimezone(\App\Support\HubTimezone::NAME)->toDateString());
            $attendance->update([
                'employee_profile_id' => $data['employee_profile_id'], 'snapshot' => $snapshot,
                'signature' => $this->signatures->store($data['signature'], 'training-rosters'),
                'signature_signed_at' => now()->utc(),
                'voided_at' => null,
            ]);
            $this->audit($session, 'attendance.corrected', $actor, $attendance, $before, $attendance->toArray(), $reason);
            $this->advance($session);

            return $attendance;
        });
    }

    public function addException(TrainingSession $session, int $employeeProfileId, string $signature, User $actor, string $reason, array $suppliedNameParts = []): TrainingAttendance
    {
        $this->authorize($actor, 'correct', $session);
        $reason = $this->reason($reason);
        $names = Validator::make($suppliedNameParts, ['first_name' => ['required', 'string', 'max:100', 'regex:/\S/u'], 'last_name' => ['required', 'string', 'max:100', 'regex:/\S/u']])->validate();

        return DB::transaction(function () use ($session, $employeeProfileId, $signature, $actor, $reason, $names, $suppliedNameParts): TrainingAttendance {
            $session = $this->locked($session);
            $this->expectedRevision($session, isset($suppliedNameParts['expected_revision']) ? (int) $suppliedNameParts['expected_revision'] : null);
            $this->editable($session, ['open', 'closed']);
            $employee = Employee::query()->where('roster_status', 'active')->findOrFail($employeeProfileId);
            $attendance = $session->attendances()->where('employee_profile_id', $employeeProfileId)->first();
            if ($attendance !== null && $attendance->voided_at === null) {
                $this->reject('employee_profile_id', 'This employee already has an attendance record.');
            }
            $before = $attendance?->toArray();
            $attributes = [
                'employee_profile_id' => $employeeProfileId,
                'snapshot' => $names + ['employee_id' => (string) $employee->employee_id, 'display_name' => (string) $employee->name,
                    'rank' => $employee->rank, 'shift' => null, 'term_label' => null, 'assignment_source' => 'supervised_exception',
                    'assignment_id' => null, 'as_of' => now(\App\Support\HubTimezone::NAME)->toDateString(), 'name_source' => 'instructor_confirmed'],
                'signature' => $this->signatures->store($signature, 'training-rosters'), 'signed_at' => now()->utc(), 'signature_signed_at' => now()->utc(),
                'voided_at' => null, 'client_submission_id' => (string) Str::uuid(),
                'request_hash' => $this->requestHash($session->id, $employeeProfileId, $signature),
            ];
            if ($attendance === null) {
                $attendance = $session->attendances()->create($attributes);
            } else {
                // Keep the original receipt reserved permanently, including after re-signing.
                unset($attributes['client_submission_id'], $attributes['request_hash']);
                $attendance->update($attributes);
            }
            $this->audit($session, $before === null ? 'attendance.exception' : 'attendance.resigned', $actor, $attendance, $before, $attendance->toArray(), $reason);
            $this->advance($session);

            return $attendance;
        });
    }

    public function certify(TrainingSession $session, User $actor, int $oicEmployeeProfileId, string $signature, ?int $expectedRevision = null): TrainingRosterVersion
    {
        $this->authorize($actor, 'certify', $session);
        if ((int) $actor->employee_profile_id !== $oicEmployeeProfileId) {
            $this->reject('oic_employee_profile_id', 'You must certify with your own linked personnel identity.');
        }

        return DB::transaction(function () use ($session, $actor, $oicEmployeeProfileId, $signature, $expectedRevision): TrainingRosterVersion {
            $session = $this->locked($session);
            $this->expectedRevision($session, $expectedRevision);
            $this->editable($session, ['closed', 'submitted']);
            $employee = Employee::query()->where('roster_status', 'active')->where('employee_id', $actor->employee_id)->findOrFail($oicEmployeeProfileId);
            $certification = [
                'oic_snapshot' => ['employee_id' => (string) $employee->employee_id, 'display_name' => (string) $employee->name, 'employee_profile_id' => $employee->id],
                'oic_signature' => $this->signatures->store($signature, 'training-rosters'),
                'certified_at' => now()->utc()->toIso8601String(), 'certified_by' => $actor->id,
            ];
            $before = $session->toArray();
            $signoff = $session->versions()->where('revision', $session->revision)->first()?->snapshot['instructor_signoff'] ?? null;
            $session->update(['status' => 'certified', 'oic_employee_profile_id' => $employee->id, 'revision' => $session->revision + 1]);
            $version = $this->capture($session, $certification, $signoff);
            $this->audit($session, 'session.certified', $actor, null, $before, ['version_id' => $version->id, 'certification' => $certification]);

            return $version;
        });
    }

    public function snapshot(TrainingSession $session): TrainingRosterVersion
    {
        return DB::transaction(fn (): TrainingRosterVersion => $this->capture($this->locked($session)));
    }

    private function capture(TrainingSession $session, ?array $certification = null, ?array $instructorSignoff = null): TrainingRosterVersion
    {
        $existing = $session->versions()->where('revision', $session->revision)->first();
        if ($existing !== null) {
            return $existing;
        }
        $attendance = $session->attendances()->whereNull('voided_at')->orderBy('signed_at')->orderBy('id')->get()
            ->map(static fn (TrainingAttendance $item): array => [
                'id' => $item->id, 'employee_profile_id' => $item->employee_profile_id, 'snapshot' => $item->snapshot,
                'signature' => $item->signature, 'signed_at' => $item->signed_at->utc()->toIso8601String(),
                'signature_signed_at' => ($item->signature_signed_at ?? $item->signed_at)->utc()->toIso8601String(),
            ])->all();

        return $session->versions()->create([
            'revision' => $session->revision,
            'snapshot' => ['schema_version' => 1, 'session' => [
                'id' => $session->id, 'public_id' => $session->public_id, 'course_name' => $session->course_name,
                'classifications' => $session->classifications, 'held_on' => $session->held_on->toDateString(),
                'starts_at' => $session->starts_at?->utc()->toIso8601String(), 'location' => $session->location,
                'status' => $session->status, 'revision' => $session->revision,
            ], 'attendance' => $attendance, 'certification' => $certification, 'instructor_signoff' => $instructorSignoff,
                'submission' => $session->submitted_at === null ? null : ['submitted_at' => $session->submitted_at->utc()->toIso8601String(), 'submitted_by' => $session->submitted_by],
                'captured_at' => now()->utc()->toIso8601String()],
            'certified_at' => $certification['certified_at'] ?? null, 'certified_by' => $certification['certified_by'] ?? null,
            'oic_employee_profile_id' => $certification === null ? null : $session->oic_employee_profile_id,
            'oic_snapshot' => $certification['oic_snapshot'] ?? null, 'oic_signature' => $certification['oic_signature'] ?? null,
        ]);
    }

    private function advance(TrainingSession $session): void
    {
        $session->update(['revision' => $session->revision + 1]);
    }

    private function candidate(int $employeeProfileId, ?string $asOf = null): array
    {
        foreach ($this->assignments->candidates($asOf ?? now(\App\Support\HubTimezone::NAME)->toDateString()) as $candidate) {
            if ($candidate['employee_profile_id'] === $employeeProfileId) {
                return $candidate;
            }
        }
        $this->reject('employee_profile_id', 'This employee has no verified published bid selection. Ask the instructor for assistance.');
    }

    private function locked(TrainingSession $session): TrainingSession
    {
        $session = TrainingSession::query()->lockForUpdate()->findOrFail($session->id);
        $this->digital($session);

        return $session;
    }

    private function expectedRevision(TrainingSession $session, ?int $expected): void
    {
        if ($expected !== null && $session->revision !== $expected) {
            $this->reject('expected_revision', 'This roster changed while the form was open. Reload and review the current record before saving.');
        }
    }

    private function editable(TrainingSession $session, array $statuses): void
    {
        if (! in_array($session->status, $statuses, true)) {
            $this->reject('status', 'This roster is not open for that change. Reopen a certified roster with a reason before correcting it.');
        }
    }

    private function authorize(User $actor, string $ability, TrainingSession|string $subject): void
    {
        abort_unless($actor->isAuthenticationAllowed(), 403);
        Gate::forUser($actor)->authorize($ability, $subject);
    }

    /** @return array{lead_employee_profile_id: int|string, lead_signature: string, lead_confirmed: mixed} */
    private function leadSignoffData(array $data): array
    {
        return Validator::make($data, [
            'lead_employee_profile_id' => ['required', 'integer', 'min:1'],
            'lead_signature' => ['required', 'string', 'max:1400000'],
            'lead_confirmed' => ['required', 'accepted'],
        ])->validate();
    }

    private function signoffRequestHash(TrainingSession $session, User $actor, int $expectedRevision, array $data): string
    {
        return hash('sha256', json_encode([
            $session->id, $actor->id, $expectedRevision, (int) $data['lead_employee_profile_id'], $data['lead_signature'], true,
        ], JSON_THROW_ON_ERROR));
    }

    private function courseData(array $data): array
    {
        if (is_array($data['classifications'] ?? null)) {
            $data['classifications'] = TrainingCourse::normalizeClassifications($data['classifications']);
        }

        return Validator::make($data, [
            'name' => ['required', 'string', 'max:200', 'regex:/\S/u'],
            'classifications' => ['required', 'array', 'min:1', 'max:16'],
            'classifications.*' => ['bail', 'required', 'string', 'max:80', 'regex:/\S/u', 'distinct:ignore_case'],
            'description' => ['nullable', 'string', 'max:10000'], 'default_location' => ['nullable', 'string', 'max:255'],
            'track_attendance_matrix' => ['sometimes', 'boolean'],
        ])->validate();
    }

    private function sessionData(array $data): array
    {
        if (is_array($data['classifications'] ?? null)) {
            $data['classifications'] = TrainingCourse::normalizeClassifications($data['classifications']);
        }

        $attributes = Validator::make($data, [
            'held_on' => ['required', 'date_format:Y-m-d'], 'starts_at' => ['nullable', 'date'],
            'training_module_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'location' => ['nullable', 'string', 'max:255'],
            'course_name' => ['sometimes', 'required', 'string', 'max:200', 'regex:/\S/u'],
            'classifications' => ['sometimes', 'required', 'array', 'min:1', 'max:16'],
            'classifications.*' => ['bail', 'required', 'string', 'max:80', 'regex:/\S/u', 'distinct:ignore_case'],
            'oic_employee_profile_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('roster_status', 'active')],
        ])->validate();

        if (isset($attributes['training_module_id'])) {
            $attributes['training_module_id'] = (int) $attributes['training_module_id'];
        }
        if (isset($attributes['starts_at'])) {
            $attributes['starts_at'] = CarbonImmutable::parse($attributes['starts_at'])->utc();
        }

        return $attributes;
    }

    private function reason(?string $reason): string
    {
        return Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:3', 'max:2000', 'regex:/\S/u']])->validate()['reason'];
    }

    private function validateModule(TrainingCourse $course, mixed $moduleId): void
    {
        if ($moduleId !== null && ! TrainingModule::query()->whereKey($moduleId)->where('training_course_id', $course->id)->exists()) {
            $this->reject('training_module_id', 'Choose a module belonging to this course.');
        }
    }

    private function digital(TrainingSession $session): void
    {
        if ($session->isPaper()) {
            $this->reject('session', 'Paper roster evidence is managed through the paper review workflow.');
        }
    }

    private function requestHash(int $sessionId, int $employeeId, string $signature): string
    {
        return hash('sha256', json_encode([$sessionId, $employeeId, $signature], JSON_THROW_ON_ERROR));
    }

    private function audit(?TrainingSession $session, string $action, ?User $actor, ?TrainingAttendance $attendance, ?array $before, ?array $after, ?string $reason = null): void
    {
        TrainingRosterAudit::query()->create([
            'training_session_id' => $session?->id, 'training_attendance_id' => $attendance?->id,
            'actor_user_id' => $actor?->id, 'action' => $action, 'before' => $before, 'after' => $after,
            'reason' => $reason, 'created_at' => now()->utc(),
        ]);
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
