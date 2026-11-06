<?php

declare(strict_types=1);

namespace App\Services\Accidents;

use App\Jobs\GenerateAccidentReportPdf;
use App\Models\AccidentReport;
use App\Models\AccidentReportEvent;
use App\Models\Employee;
use App\Models\PersonnelDocument;
use App\Models\UnitMasterVehicle;
use App\Models\User;
use App\Notifications\Accidents\AccidentReportNotification;
use App\Services\Notifications\NotificationCategoryRegistry;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Personnel\PersonnelDocumentService;
use App\Services\PersonnelRequests\SignatureImageService;
use App\Support\HubTimezone;
use App\Support\Personnel\PersonnelAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The accident workflow. A Vehicle Accident Report starts with the driver's part; when it is submitted the
 * administrators are alerted at once and the report is routed to the chosen supervisor, who completes the
 * supervisor's part on the same record. A Supervisor's Report of Accident is completed by a supervisor in one
 * step. Finished reports get the official PDF. Nothing here deletes a submitted report or overwrites history.
 */
final class AccidentReportService
{
    public function __construct(
        private readonly SignatureImageService $signatures,
        private readonly NotificationDispatchService $notifications,
        private readonly PersonnelDocumentService $documents,
        private readonly AccidentReportPdf $pdf,
    ) {}

    /** Starts a draft, or returns the draft already started with the same client key (double tap, retry). */
    public function start(User $actor, string $type, string $clientKey, ?Employee $subject = null): AccidentReport
    {
        abort_unless(array_key_exists($type, AccidentReport::TYPES) && Str::isUuid($clientKey), 422);
        $actorEmployee = $this->employee($actor);
        if ($type === AccidentReport::TYPE_SUPERVISOR) {
            abort_unless(PersonnelAccess::canSupervise($actor), 403, 'Only a supervisor can complete a Supervisor\'s Report of Accident.');
        }
        $existing = AccidentReport::query()->where('created_by_user_id', $actor->getKey())->where('client_key', $clientKey)->first();
        if ($existing) {
            return $existing;
        }
        $subject ??= $type === AccidentReport::TYPE_VEHICLE ? $actorEmployee : null;
        $this->assertMayNameSubject($actor, $actorEmployee, $type, $subject);

        try {
            return $this->createDraft($actor, $actorEmployee, $type, $clientKey, $subject);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Two taps raced past the lookup above: return the draft the first one created.
            return AccidentReport::query()->where('created_by_user_id', $actor->getKey())->where('client_key', $clientKey)->firstOrFail();
        }
    }

    /**
     * The driver's part of a Vehicle Accident Report is the driver's own account, signed by them. Only a supervisor
     * or an accident administrator may start one about somebody else.
     */
    private function assertMayNameSubject(User $actor, Employee $actorEmployee, string $type, ?Employee $subject): void
    {
        if ($type === AccidentReport::TYPE_VEHICLE && $subject !== null && (int) $subject->getKey() !== (int) $actorEmployee->getKey()
            && ! PersonnelAccess::canSupervise($actor) && ! PersonnelAccess::canManageAccidents($actor)) {
            throw ValidationException::withMessages(['subject_employee_id' => 'Report your own accident. A supervisor can report one for another member.']);
        }
    }

    private function createDraft(User $actor, Employee $actorEmployee, string $type, string $clientKey, ?Employee $subject): AccidentReport
    {
        return DB::transaction(function () use ($actor, $actorEmployee, $type, $clientKey, $subject): AccidentReport {
            $report = AccidentReport::query()->create([
                'reference' => $this->reference(),
                'report_type' => $type,
                'status' => AccidentReport::STATUS_DRAFT,
                'created_by_user_id' => $actor->getKey(),
                'client_key' => $clientKey,
                'reporter_employee_id' => $actorEmployee->getKey(),
                'subject_employee_id' => $subject?->getKey(),
                'supervisor_employee_id' => $type === AccidentReport::TYPE_SUPERVISOR ? $actorEmployee->getKey() : null,
                'supervisor_user_id' => $type === AccidentReport::TYPE_SUPERVISOR ? $actor->getKey() : null,
                'driver_data' => $this->prefill($type, $subject, $actorEmployee),
                'supervisor_data' => [],
                'revision' => 1,
                'station_label' => $subject ? $this->stationLabel($subject) : null,
            ]);
            $this->event($report, $actor, 'started', ['type' => $type]);

            return $report;
        });
    }

    /**
     * Autosave. The caller sends the revision it last saw; a stale revision gets 409 with the current report so
     * nothing typed on another device is silently overwritten.
     *
     * @param  array{data?: array, unit_master_vehicle_id?: int|null, subject_employee_id?: int|null, supervisor_employee_id?: int|null}  $changes
     */
    public function save(User $actor, AccidentReport $report, int $revision, array $changes): AccidentReport
    {
        $section = $this->editableSection($actor, $report);

        return DB::transaction(function () use ($actor, $report, $revision, $changes, $section): AccidentReport {
            $locked = AccidentReport::query()->lockForUpdate()->findOrFail($report->getKey());
            if ($locked->revision !== $revision) {
                throw new HttpException(409, 'revision_conflict');
            }
            $column = $section === 'supervisor' ? 'supervisor_data' : 'driver_data';
            $data = array_merge($locked->{$column} ?? [], $changes['data'] ?? []);
            $data = AccidentReportSchema::validate($locked->report_type, $section, array_filter($data, static fn ($v) => $v !== null), false);
            $updates = [$column => $data];

            if ($section === 'driver') {
                if (array_key_exists('subject_employee_id', $changes)) {
                    $subject = $changes['subject_employee_id'] ? Employee::query()->find((int) $changes['subject_employee_id']) : null;
                    abort_if($changes['subject_employee_id'] && ! $subject, 422, 'Choose the employee involved from the list.');
                    $this->assertMayNameSubject($actor, $this->employee($actor), $locked->report_type, $subject);
                    $updates['subject_employee_id'] = $subject?->getKey();
                    $updates['station_label'] = $subject ? $this->stationLabel($subject) : null;
                    if ($subject) {
                        $updates[$column] = array_merge($data, $this->identityFields($locked->report_type, $subject));
                    }
                }
                if (array_key_exists('unit_master_vehicle_id', $changes) && $locked->report_type === AccidentReport::TYPE_VEHICLE) {
                    $vehicle = $changes['unit_master_vehicle_id'] ? UnitMasterVehicle::query()->find((int) $changes['unit_master_vehicle_id']) : null;
                    abort_if($changes['unit_master_vehicle_id'] && ! $vehicle, 422, 'Choose the City vehicle from the list.');
                    $updates['unit_master_vehicle_id'] = $vehicle?->getKey();
                    $updates['apparatus_id'] = $vehicle?->apparatus?->getKey();
                    if ($vehicle) {
                        $updates[$column] = array_merge($updates[$column], $this->vehicleFields($vehicle));
                    }
                }
                if (array_key_exists('supervisor_employee_id', $changes) && $locked->report_type === AccidentReport::TYPE_VEHICLE) {
                    $updates['supervisor_employee_id'] = $this->eligibleSupervisor($changes['supervisor_employee_id'])?->getKey();
                }
            }

            $locked->forceFill($updates + ['revision' => $locked->revision + 1, 'last_autosaved_at' => now()])->save();

            return $locked;
        });
    }

    /** The driver's part (vehicle report) or a whole Supervisor's Report of Accident is submitted. */
    public function submit(User $actor, AccidentReport $report, int $revision, string $signature, bool $attested): AccidentReport
    {
        abort_unless($report->created_by_user_id === $actor->getKey(), 403);
        if ($report->submitted_at !== null) {
            return $report; // A repeated submit (retry, double tap) changes nothing and raises no second alert.
        }
        if (! $attested) {
            throw ValidationException::withMessages(['attest' => 'Confirm that the report is accurate before you sign.']);
        }
        $vehicle = $report->report_type === AccidentReport::TYPE_VEHICLE;

        return DB::transaction(function () use ($actor, $report, $revision, $signature, $vehicle): AccidentReport {
            $locked = AccidentReport::query()->lockForUpdate()->findOrFail($report->getKey());
            if ($locked->submitted_at !== null) {
                return $locked;
            }
            if ($locked->revision !== $revision) {
                throw new HttpException(409, 'revision_conflict');
            }
            $data = AccidentReportSchema::validate($locked->report_type, 'driver', $locked->driver_data ?? [], true);
            $errors = [];
            if (! $locked->subject_employee_id) {
                $errors['subject_employee_id'] = 'Choose the employee involved in the accident.';
            }
            if ($vehicle && ! $locked->unit_master_vehicle_id) {
                $errors['unit_master_vehicle_id'] = 'Choose the City vehicle involved.';
            }
            if ($vehicle && ! $locked->supervisor_employee_id) {
                $errors['supervisor_employee_id'] = 'Choose the supervisor who will complete the supervisor\'s part.';
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $stored = $this->signatures->store($signature, 'accident-reports');
            $now = now();
            $locked->forceFill([
                'driver_data' => $data,
                'status' => $vehicle ? AccidentReport::STATUS_AWAITING_SUPERVISOR : AccidentReport::STATUS_COMPLETE,
                'submitted_at' => $now,
                'occurred_at' => $this->occurredAt($data),
                'revision' => $locked->revision + 1,
                ...($vehicle ? [
                    'driver_signature_path' => $stored['path'],
                    'driver_signature_sha256' => $stored['sha256'],
                ] : [
                    'supervisor_signature_path' => $stored['path'],
                    'supervisor_signature_sha256' => $stored['sha256'],
                    'supervisor_completed_at' => $now,
                    'completed_at' => $now,
                ]),
            ])->save();
            $this->event($locked, $actor, 'submitted', ['revision' => $locked->revision]);
            // The administrators are alerted on the first submission, not when the supervisor finishes.
            $this->alertAdministrators($locked);
            if ($vehicle) {
                $this->notifySupervisor($locked);
            } else {
                $this->queuePdf($locked);
            }

            return $locked;
        });
    }

    /** The assigned supervisor (or an authorized supervisor) completes page 1 of the Vehicle Accident Report. */
    public function completeSupervisorPart(User $actor, AccidentReport $report, int $revision, string $signature, bool $attested): AccidentReport
    {
        $this->assertSupervisorFor($actor, $report);
        if ($report->supervisor_completed_at !== null) {
            return $report;
        }
        if (! $attested) {
            throw ValidationException::withMessages(['attest' => 'Confirm that the supervisor\'s part is accurate before you sign.']);
        }

        return DB::transaction(function () use ($actor, $report, $revision, $signature): AccidentReport {
            $locked = AccidentReport::query()->lockForUpdate()->findOrFail($report->getKey());
            if ($locked->supervisor_completed_at !== null) {
                return $locked;
            }
            if ($locked->revision !== $revision) {
                throw new HttpException(409, 'revision_conflict');
            }
            $data = AccidentReportSchema::validate($locked->report_type, 'supervisor', $locked->supervisor_data ?? [], true);
            $stored = $this->signatures->store($signature, 'accident-reports');
            $now = now();
            $locked->forceFill([
                'supervisor_data' => $data,
                'supervisor_employee_id' => $this->employee($actor)->getKey(),
                'supervisor_user_id' => $actor->getKey(),
                'supervisor_signature_path' => $stored['path'],
                'supervisor_signature_sha256' => $stored['sha256'],
                'supervisor_completed_at' => $now,
                'completed_at' => $now,
                'status' => AccidentReport::STATUS_COMPLETE,
                'revision' => $locked->revision + 1,
            ])->save();
            $this->event($locked, $actor, 'supervisor_completed', ['revision' => $locked->revision]);
            $this->queuePdf($locked);

            return $locked;
        });
    }

    /** Opening the alert is not resolving the accident: acknowledgment only records who has seen it. */
    public function acknowledge(User $actor, AccidentReport $report): AccidentReport
    {
        abort_unless(PersonnelAccess::canAcknowledgeAccidents($actor) && $report->submitted_at !== null, 403);
        if ($report->alert_acknowledged_at === null) {
            $report->forceFill(['alert_acknowledged_at' => now(), 'alert_acknowledged_by_user_id' => $actor->getKey()])->save();
            $this->event($report, $actor, 'acknowledged');
        }

        return $report;
    }

    public function setFollowUp(User $actor, AccidentReport $report, bool $required, ?string $note): AccidentReport
    {
        abort_unless(PersonnelAccess::canManageAccidents($actor) && $report->submitted_at !== null, 403);
        $report->forceFill(['follow_up_required' => $required, 'follow_up_note' => $required ? Str::limit(trim((string) $note), 1000, '') : null])->save();
        $this->event($report, $actor, $required ? 'follow_up_required' : 'follow_up_cleared', ['note' => $required ? $report->follow_up_note : null]);

        return $report;
    }

    public function resolve(User $actor, AccidentReport $report, string $note): AccidentReport
    {
        abort_unless(PersonnelAccess::canManageAccidents($actor) && $report->submitted_at !== null, 403);
        $note = trim($note);
        if ($note === '') {
            throw ValidationException::withMessages(['note' => 'Say how the accident was addressed.']);
        }
        $report->forceFill([
            'resolved_at' => now(), 'resolved_by_user_id' => $actor->getKey(), 'resolution_note' => Str::limit($note, 1000, ''),
            'alert_acknowledged_at' => $report->alert_acknowledged_at ?? now(),
            'alert_acknowledged_by_user_id' => $report->alert_acknowledged_by_user_id ?? $actor->getKey(),
        ])->save();
        $this->event($report, $actor, 'resolved', ['note' => $report->resolution_note]);

        return $report;
    }

    public function reopen(User $actor, AccidentReport $report): AccidentReport
    {
        abort_unless(PersonnelAccess::canManageAccidents($actor), 403);
        $report->forceFill(['resolved_at' => null, 'resolved_by_user_id' => null])->save();
        $this->event($report, $actor, 'reopened');

        return $report;
    }

    /**
     * An authorized correction after submission. The earlier values are kept in the audit event and the earlier
     * PDF stays as a prior version; the corrected report gets a new PDF version.
     */
    public function amend(User $actor, AccidentReport $report, string $section, array $changes, string $reason): AccidentReport
    {
        abort_unless(PersonnelAccess::canManageAccidents($actor) && $report->submitted_at !== null, 403);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give the reason for the correction.']);
        }
        $column = $section === 'supervisor' ? 'supervisor_data' : 'driver_data';
        abort_if($section === 'supervisor' && $report->report_type !== AccidentReport::TYPE_VEHICLE, 422);

        return DB::transaction(function () use ($actor, $report, $section, $column, $changes, $reason): AccidentReport {
            $locked = AccidentReport::query()->lockForUpdate()->findOrFail($report->getKey());
            $before = $locked->{$column} ?? [];
            $after = AccidentReportSchema::validate($locked->report_type, $section, array_merge($before, $changes), $section === 'driver' || $locked->supervisor_completed_at !== null);
            $changed = array_keys(array_filter($after, static fn ($value, $key) => ($before[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));
            if ($changed === []) {
                return $locked;
            }
            $locked->forceFill([$column => $after, 'revision' => $locked->revision + 1])->save();
            $this->event($locked, $actor, 'amended', [
                'section' => $section,
                'reason' => Str::limit(trim($reason), 1000, ''),
                'before' => array_intersect_key($before, array_flip($changed)),
                'after' => array_intersect_key($after, array_flip($changed)),
            ]);
            if ($locked->completed_at !== null) {
                $this->queuePdf($locked);
            }

            return $locked;
        });
    }

    /**
     * Produces and stores the official PDF for the report's current revision. Safe to run more than once: a
     * revision that already has its PDF is left alone.
     */
    public function generateDocument(AccidentReport $report): ?PersonnelDocument
    {
        $report->refresh();
        if ($report->completed_at === null || $report->pdf_revision === $report->revision) {
            return $report->document;
        }
        $bytes = $this->pdf->render($report);

        return DB::transaction(function () use ($report, $bytes): PersonnelDocument {
            $locked = AccidentReport::query()->lockForUpdate()->findOrFail($report->getKey());
            if ($locked->pdf_revision === $locked->revision && $locked->document) {
                return $locked->document;
            }
            $document = $this->documents->storeGeneratedPdf($bytes, [
                'employee_id' => $locked->subject_employee_id,
                'title' => $locked->typeLabel().' '.$locked->reference,
                'category' => 'accident_report',
                // The member involved sees their own finished report in My Documents; nobody else does by default.
                'visible_to_employee' => true,
                'access_level' => PersonnelDocument::ACCESS_ACCIDENT,
                'original_name' => Str::slug($locked->typeLabel()).'-'.$locked->reference.'.pdf',
                'description' => 'Official City form completed in MBFD Hub.',
            ], null, $locked, $locked->document);
            $locked->forceFill(['document_id' => $document->getKey(), 'pdf_revision' => $locked->revision])->save();
            $this->event($locked, null, 'pdf_generated', ['document' => $document->ulid, 'version' => $document->version]);

            return $document;
        });
    }

    /**
     * Reports this member started, is involved in, or must complete as supervisor.
     *
     * @return Builder<AccidentReport>
     */
    public function forMember(User $actor): Builder
    {
        $employeeId = (int) $this->employee($actor)->getKey();

        return AccidentReport::query()->with(['subject', 'supervisor', 'vehicle'])
            ->where(fn (Builder $query) => $query->where('created_by_user_id', $actor->getKey())
                ->orWhere(fn (Builder $q) => $q->whereNotNull('submitted_at')->involvingEmployee($employeeId)))
            ->latest('updated_at');
    }

    /** Which part of the report this person may change right now; 403 when none. */
    public function editableSection(User $actor, AccidentReport $report): string
    {
        if ($report->status === AccidentReport::STATUS_DRAFT && $report->created_by_user_id === $actor->getKey()) {
            return 'driver';
        }
        if ($report->status === AccidentReport::STATUS_AWAITING_SUPERVISOR && $this->canCompleteSupervisorPart($actor, $report)) {
            return 'supervisor';
        }
        abort(403, 'This part of the report is not open for you to change.');
    }

    public function canCompleteSupervisorPart(User $actor, AccidentReport $report): bool
    {
        if ($report->report_type !== AccidentReport::TYPE_VEHICLE || $report->status !== AccidentReport::STATUS_AWAITING_SUPERVISOR) {
            return false;
        }
        $employeeId = $actor->employee_profile_id !== null ? (int) $actor->employee_profile_id : null;
        // A supervisor cannot complete the supervisor's part of their own accident.
        if ($employeeId !== null && $employeeId === (int) $report->subject_employee_id) {
            return false;
        }
        if ($employeeId !== null && $employeeId === (int) $report->supervisor_employee_id && PersonnelAccess::canSupervise($actor)) {
            return true;
        }

        // Reassignment: someone holding the supervise or manage permission may complete a report routed elsewhere.
        return $employeeId !== null && ($actor->hasDirectWebPermission('personnel.accidents.supervise') || PersonnelAccess::canManageAccidents($actor));
    }

    private function assertSupervisorFor(User $actor, AccidentReport $report): void
    {
        abort_unless($this->canCompleteSupervisorPart($actor, $report) || $report->supervisor_completed_at !== null, 403);
    }

    private function eligibleSupervisor(mixed $employeeId): ?Employee
    {
        if (! $employeeId) {
            return null;
        }
        $employee = Employee::query()->with('user')->find((int) $employeeId);
        $user = $employee?->user;
        if (! $employee || ! $user instanceof User || ! PersonnelAccess::canSupervise($user)) {
            throw ValidationException::withMessages(['supervisor_employee_id' => 'Choose a supervisor from the list.']);
        }

        return $employee;
    }

    /** Officers who can be chosen to complete the supervisor's part, for the picker. */
    public function supervisorOptions(?int $exceptEmployeeId = null): array
    {
        // Officers by rank, plus anyone granted the supervise permission directly (one query each, not per person).
        $granted = User::query()->whereHas('permissions', fn (Builder $q) => $q->where('name', 'personnel.accidents.supervise')->where('guard_name', 'web'))
            ->whereNotNull('employee_profile_id')->pluck('employee_profile_id')->map(fn ($id): int => (int) $id)->all();

        return Employee::query()->with('user')
            ->where(fn (Builder $q) => $q->whereNull('roster_status')->orWhere('roster_status', '!=', 'departed'))
            ->when($exceptEmployeeId, fn (Builder $q) => $q->whereKeyNot($exceptEmployeeId))
            ->orderBy('name')->get()
            ->filter(fn (Employee $employee): bool => $employee->user instanceof User && $employee->user->isAuthenticationAllowed()
                && (PersonnelAccess::isSupervisorRank($employee->rank) || in_array((int) $employee->getKey(), $granted, true)))
            ->map(fn (Employee $employee): array => ['id' => $employee->getKey(), 'name' => $employee->name, 'rank' => $employee->rank, 'station' => $employee->station])
            ->values()->all();
    }

    private function prefill(string $type, ?Employee $subject, Employee $actor): array
    {
        $data = $subject ? $this->identityFields($type, $subject) : [];
        if ($type === AccidentReport::TYPE_SUPERVISOR) {
            $data['supervisor_title'] = $actor->rank;
        }

        return array_filter($data, static fn ($value) => $value !== null && $value !== '');
    }

    private function identityFields(string $type, Employee $employee): array
    {
        $division = $this->division($employee);

        return $type === AccidentReport::TYPE_VEHICLE ? [
            'employee_name' => $employee->name,
            'dept_div' => trim('Fire'.($division ? ' / '.$division : '')),
            'job_classification' => $employee->rank,
        ] : [
            'employee_name' => $employee->name,
            'employee_id' => $employee->employee_id,
            'department' => 'Fire',
            'division' => $division,
            'classification' => $employee->rank,
        ];
    }

    private function vehicleFields(UnitMasterVehicle $vehicle): array
    {
        $make = trim(implode(' ', array_filter([$vehicle->year, $vehicle->make, $vehicle->model])));
        $tag = $vehicle->tag_number;

        return array_filter([
            'city_vehicle_number' => (string) ($vehicle->veh_number ?? ''),
            'vehicle_type' => trim(implode(' ', array_filter([$vehicle->apparatus?->designation, $vehicle->asset_category ? '('.$vehicle->asset_category.')' : null]))) ?: null,
            'make_and_license' => trim($make.($tag ? ' / Tag '.$tag : '')) ?: null,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function division(Employee $employee): ?string
    {
        $bid = $employee->bidAssignments()->whereNull('superseded_at')->orderByDesc('bid_year')->first();

        return $bid?->division_label ?: null;
    }

    private function stationLabel(Employee $employee): ?string
    {
        $bid = $employee->bidAssignments()->whereNull('superseded_at')->orderByDesc('bid_year')->first();

        return $bid?->station_label ?: ($employee->station ?: null);
    }

    private function occurredAt(array $data): ?Carbon
    {
        if (empty($data['accident_date'])) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d H:i', $data['accident_date'].' '.($data['accident_time'] ?? '00:00'), HubTimezone::NAME)->utc();
    }

    private function employee(User $actor): Employee
    {
        $employee = $actor->employeeProfile;
        abort_unless($employee instanceof Employee, 403, 'Your account is not linked to an employee record.');

        return $employee;
    }

    private function reference(): string
    {
        do {
            $reference = 'AR-'.now(HubTimezone::NAME)->format('ymd').'-'.Str::upper(Str::random(4));
        } while (AccidentReport::withTrashed()->where('reference', $reference)->exists());

        return $reference;
    }

    private function queuePdf(AccidentReport $report): void
    {
        $id = (int) $report->getKey();
        DB::afterCommit(static fn () => GenerateAccidentReportPdf::dispatch($id));
    }

    private function alertAdministrators(AccidentReport $report): void
    {
        $this->notifications->dispatch(
            NotificationCategoryRegistry::ACCIDENT_REPORTS,
            new AccidentReportNotification(AccidentReportNotification::AUDIENCE_ADMIN, (int) $report->getKey(), $report->ulid, $report->typeLabel(), $report->reference),
            null,
            AccidentReportNotification::eventKey('alert', (int) $report->getKey()),
            writeAhead: true,
        );
    }

    private function notifySupervisor(AccidentReport $report): void
    {
        $user = $report->supervisor?->user;
        if (! $user instanceof User) {
            return;
        }
        $this->notifications->dispatch(
            NotificationCategoryRegistry::ACCIDENT_SUPERVISOR_REQUESTS,
            new AccidentReportNotification(AccidentReportNotification::AUDIENCE_SUPERVISOR, (int) $report->getKey(), $report->ulid, $report->typeLabel(), $report->reference),
            [$user],
            AccidentReportNotification::eventKey('supervisor', (int) $report->getKey()),
        );
    }

    private function event(AccidentReport $report, ?User $actor, string $action, array $metadata = []): void
    {
        AccidentReportEvent::query()->create([
            'accident_report_id' => $report->getKey(),
            'actor_user_id' => $actor?->getKey(),
            'action' => $action,
            'metadata' => $metadata,
        ]);
    }
}
