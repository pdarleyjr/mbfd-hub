<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\ApparatusServiceTicketSubmissionResult;
use App\Enums\ApparatusServiceTicketCategory;
use App\Enums\ApparatusServiceTicketPriority;
use App\Enums\ApparatusServiceTicketStatus;
use App\Models\Apparatus;
use App\Models\ApparatusServiceTicket;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ApparatusServiceTicketWorkflowService
{
    private const OPERATIONAL_STATUSES = ['In Service', 'Out of Service', 'Maintenance', 'Available', 'Reserve'];

    public function __construct(private readonly ApparatusServiceTicketSideEffectService $sideEffects) {}

    /** @param array<string, mixed> $data */
    public function submitFromEmployee(Employee $employee, Apparatus $apparatus, array $data): ApparatusServiceTicketSubmissionResult
    {
        $validated = Validator::make($data, $this->creationRules())->validate();

        return $this->createIdempotently($apparatus, $validated, [
            'requested_by_employee_id' => $employee->id,
            'requester_name_snapshot' => $employee->name,
            'origin' => 'station',
            'status' => ApparatusServiceTicketStatus::Submitted->value,
        ], changedByEmployee: $employee);
    }

    /** @param array<string, mixed> $data */
    public function createFleetTicket(Apparatus $apparatus, User $actor, array $data): ApparatusServiceTicketSubmissionResult
    {
        $validated = Validator::make($data, array_merge($this->creationRules(), [
            'status' => ['nullable', Rule::in([
                ApparatusServiceTicketStatus::Submitted->value,
                ApparatusServiceTicketStatus::Acknowledged->value,
                ApparatusServiceTicketStatus::Scheduled->value,
                ApparatusServiceTicketStatus::InProgress->value,
            ])],
            'scheduled_for' => ['nullable', 'date'],
            'service_type' => ['nullable', 'string', 'max:255'],
            'scheduled_location' => ['nullable', 'string', 'max:255'],
            'expected_return_at' => ['nullable', 'date', 'after_or_equal:scheduled_for'],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'assigned_vendor' => ['nullable', 'string', 'max:255'],
            'public_note' => ['nullable', 'string', 'max:5000'],
            'internal_note' => ['nullable', 'string', 'max:10000'],
        ]))->validate();

        $status = (string) ($validated['status'] ?? ApparatusServiceTicketStatus::Submitted->value);
        if ($status === ApparatusServiceTicketStatus::Scheduled->value && empty($validated['scheduled_for'])) {
            throw ValidationException::withMessages(['scheduled_for' => 'A scheduled date and time is required.']);
        }
        if ($status === ApparatusServiceTicketStatus::Scheduled->value && empty($validated['service_type'])) {
            throw ValidationException::withMessages(['service_type' => 'A service type is required.']);
        }
        if ($status === ApparatusServiceTicketStatus::Scheduled->value && empty($validated['scheduled_location'])) {
            throw ValidationException::withMessages(['scheduled_location' => 'A service location is required.']);
        }

        return $this->createIdempotently($apparatus, $validated, [
            'created_by_user_id' => $actor->id,
            'requester_name_snapshot' => $actor->name,
            'origin' => 'fleet',
            'status' => $status,
        ], changedByUser: $actor);
    }

    /** @param array<string, mixed> $data */
    public function transition(
        ApparatusServiceTicket $ticket,
        User $actor,
        ApparatusServiceTicketStatus $target,
        array $data,
    ): ApparatusServiceTicket {
        $validated = Validator::make($data, [
            'public_note' => ['nullable', 'string', 'max:5000'],
            'internal_note' => ['nullable', 'string', 'max:10000'],
            'scheduled_for' => ['nullable', 'date'],
            'service_type' => ['nullable', 'string', 'max:255'],
            'scheduled_location' => ['nullable', 'string', 'max:255'],
            'expected_return_at' => ['nullable', 'date', 'after_or_equal:scheduled_for'],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'assigned_vendor' => ['nullable', 'string', 'max:255'],
            'status_detail' => ['nullable', 'string', 'max:5000'],
            'resolution_summary' => ['nullable', 'string', 'max:10000'],
            'completed_engine_hours' => ['nullable', 'numeric', 'min:0'],
            'completed_miles' => ['nullable', 'integer', 'min:0'],
        ])->validate();

        return DB::transaction(function () use ($ticket, $actor, $target, $validated): ApparatusServiceTicket {
            $locked = ApparatusServiceTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            $current = ApparatusServiceTicketStatus::from($locked->status);
            if (! $current->canTransitionTo($target)) {
                throw ValidationException::withMessages([
                    'status' => "{$current->label()} cannot transition to {$target->label()}.",
                ]);
            }
            if ($target === ApparatusServiceTicketStatus::Scheduled && empty($validated['scheduled_for'])) {
                throw ValidationException::withMessages(['scheduled_for' => 'A scheduled date and time is required.']);
            }
            if ($target === ApparatusServiceTicketStatus::Scheduled && empty($validated['service_type']) && empty($locked->service_type)) {
                throw ValidationException::withMessages(['service_type' => 'A service type is required.']);
            }
            if ($target === ApparatusServiceTicketStatus::Scheduled && empty($validated['scheduled_location']) && empty($locked->scheduled_location)) {
                throw ValidationException::withMessages(['scheduled_location' => 'A service location is required.']);
            }

            $publicNote = $this->nullableTrim($validated['public_note'] ?? null);
            $changes = [
                'status' => $target->value,
                'current_public_response' => $publicNote ?? $locked->current_public_response,
                'status_detail' => $this->nullableTrim($validated['status_detail'] ?? null) ?? $locked->status_detail,
                'assigned_to_user_id' => $validated['assigned_to_user_id'] ?? $locked->assigned_to_user_id,
                'assigned_vendor' => $this->nullableTrim($validated['assigned_vendor'] ?? null) ?? $locked->assigned_vendor,
                'service_type' => $this->nullableTrim($validated['service_type'] ?? null) ?? $locked->service_type,
                'scheduled_location' => $this->nullableTrim($validated['scheduled_location'] ?? null) ?? $locked->scheduled_location,
                'expected_return_at' => $validated['expected_return_at'] ?? $locked->expected_return_at,
            ];
            if (array_key_exists('scheduled_for', $validated)) {
                $changes['scheduled_for'] = $validated['scheduled_for'];
            }
            if ($target === ApparatusServiceTicketStatus::Acknowledged && $locked->acknowledged_at === null) {
                $changes['acknowledged_at'] = now();
            }
            if ($target === ApparatusServiceTicketStatus::InProgress && $locked->started_at === null) {
                $changes['started_at'] = now();
            }
            if ($target === ApparatusServiceTicketStatus::Completed) {
                $changes['completed_at'] = now();
                $changes['resolution_summary'] = $this->nullableTrim($validated['resolution_summary'] ?? null);
                $changes['completed_engine_hours'] = $validated['completed_engine_hours'] ?? null;
                $changes['completed_miles'] = $validated['completed_miles'] ?? null;
            }
            if ($target === ApparatusServiceTicketStatus::Cancelled) {
                $changes['cancelled_at'] = now();
            }
            $locked->update($changes);
            $locked->updates()->create([
                'previous_status' => $current->value,
                'status' => $target->value,
                'public_note' => $publicNote,
                'internal_note' => $this->nullableTrim($validated['internal_note'] ?? null),
                'scheduled_for' => $validated['scheduled_for'] ?? null,
                'changed_by_user_id' => $actor->id,
                'metadata' => ['event' => 'status_transition'],
            ]);

            $notifyRequester = $publicNote !== null
                || $target !== $current
                || array_key_exists('scheduled_for', $validated);
            DB::afterCommit(fn () => $this->sideEffects->ticketChanged($locked, $notifyRequester));

            return $this->load($locked);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function logPmService(Apparatus $apparatus, User $actor, array $data): ApparatusServiceTicketSubmissionResult
    {
        $validated = Validator::make($data, [
            'client_submission_id' => ['required', 'uuid'],
            'service_date' => ['required', 'date'],
            'service_type' => ['required', 'string', 'max:255'],
            'service_engine_hours' => ['nullable', 'numeric', 'min:0'],
            'service_mileage' => ['nullable', 'integer', 'min:0'],
            'service_notes' => ['nullable', 'string', 'max:10000'],
        ])->validate();

        $existing = $this->existing((string) $validated['client_submission_id']);
        if ($existing !== null) {
            return new ApparatusServiceTicketSubmissionResult($this->load($existing), false);
        }

        try {
            return DB::transaction(function () use ($apparatus, $actor, $validated): ApparatusServiceTicketSubmissionResult {
                $existing = $this->existing((string) $validated['client_submission_id'], true);
                if ($existing !== null) {
                    return new ApparatusServiceTicketSubmissionResult($this->load($existing), false);
                }

                $lockedApparatus = Apparatus::query()->with('station')->lockForUpdate()->findOrFail($apparatus->id);
                $serviceDate = Carbon::parse($validated['service_date'])->startOfDay();
                $engineHours = $validated['service_engine_hours'] ?? $lockedApparatus->current_engine_hours;
                $mileage = $validated['service_mileage'] ?? $lockedApparatus->current_miles;
                $ticket = ApparatusServiceTicket::query()->create([
                    'client_submission_id' => $validated['client_submission_id'],
                    'apparatus_id' => $lockedApparatus->id,
                    'station_id' => $lockedApparatus->station_id,
                    'unit_designation_snapshot' => $this->unitLabel($lockedApparatus),
                    'created_by_user_id' => $actor->id,
                    'requester_name_snapshot' => $actor->name,
                    'origin' => 'pm',
                    'category' => ApparatusServiceTicketCategory::PreventiveMaintenance->value,
                    'title' => $validated['service_type'].' preventive maintenance',
                    'description' => $this->nullableTrim($validated['service_notes'] ?? null) ?? 'Completed preventive maintenance service.',
                    'priority' => ApparatusServiceTicketPriority::Routine->value,
                    'status' => ApparatusServiceTicketStatus::Completed->value,
                    'service_type' => $validated['service_type'],
                    'completed_at' => $serviceDate,
                    'service_engine_hours' => $engineHours,
                    'service_mileage' => $mileage,
                    'opened_engine_hours' => $engineHours,
                    'opened_miles' => $mileage,
                    'completed_engine_hours' => $engineHours,
                    'completed_miles' => $mileage,
                    'resolution_summary' => $this->nullableTrim($validated['service_notes'] ?? null) ?? 'Preventive maintenance completed.',
                    'current_public_response' => 'Preventive maintenance completed.',
                    'metadata' => ['source' => 'apparatus_pm_log'],
                ]);
                $ticket->updates()->create([
                    'status' => ApparatusServiceTicketStatus::Completed->value,
                    'public_note' => 'Preventive maintenance completed.',
                    'internal_note' => $this->nullableTrim($validated['service_notes'] ?? null),
                    'changed_by_user_id' => $actor->id,
                    'metadata' => ['event' => 'pm_logged'],
                ]);

                $apparatusChanges = [
                    'last_pm_date' => $serviceDate,
                    'last_pm_engine_hours' => $engineHours,
                    'last_pm_mileage' => $mileage,
                    'last_service_type' => $validated['service_type'],
                    'last_service_date' => $serviceDate,
                ];
                if ($engineHours !== null && (float) $engineHours > (float) ($lockedApparatus->current_engine_hours ?? 0)) {
                    $apparatusChanges['current_engine_hours'] = $engineHours;
                }
                if ($mileage !== null && (int) $mileage > (int) ($lockedApparatus->current_miles ?? 0)) {
                    $apparatusChanges['current_miles'] = $mileage;
                }
                $lockedApparatus->update($apparatusChanges);
                DB::afterCommit(fn () => $this->sideEffects->ticketCreated($ticket));

                return new ApparatusServiceTicketSubmissionResult($this->load($ticket), true);
            }, 3);
        } catch (QueryException $exception) {
            $existing = $this->existing((string) $validated['client_submission_id']);
            if ($existing !== null) {
                return new ApparatusServiceTicketSubmissionResult($this->load($existing), false);
            }
            throw $exception;
        }
    }

    public function changeOperationalStatus(
        Apparatus $apparatus,
        User $actor,
        string $status,
        ?ApparatusServiceTicket $ticket = null,
        ?string $publicNote = null,
        ?string $internalNote = null,
    ): Apparatus {
        $validated = Validator::make([
            'status' => $status,
            'public_note' => $publicNote,
            'internal_note' => $internalNote,
        ], [
            'status' => ['required', Rule::in(self::OPERATIONAL_STATUSES)],
            'public_note' => ['nullable', 'string', 'max:5000'],
            'internal_note' => ['nullable', 'string', 'max:10000'],
        ])->validate();

        return DB::transaction(function () use ($apparatus, $actor, $validated, $ticket): Apparatus {
            $locked = Apparatus::query()->lockForUpdate()->findOrFail($apparatus->id);
            $previousStatus = $locked->getAttribute('status');
            $locked->update(['status' => $validated['status']]);

            if ($ticket !== null) {
                $lockedTicket = ApparatusServiceTicket::query()->lockForUpdate()->findOrFail($ticket->id);
                if ((int) $lockedTicket->apparatus_id !== (int) $locked->id) {
                    throw ValidationException::withMessages(['apparatus' => 'The service ticket does not belong to this apparatus.']);
                }

                $safePublicNote = $this->nullableTrim($validated['public_note'] ?? null)
                    ?? "Unit operational status changed to {$validated['status']}.";
                $lockedTicket->update(['current_public_response' => $safePublicNote]);
                $lockedTicket->updates()->create([
                    'previous_status' => $lockedTicket->status,
                    'status' => $lockedTicket->status,
                    'public_note' => $safePublicNote,
                    'internal_note' => $this->nullableTrim($validated['internal_note'] ?? null),
                    'changed_by_user_id' => $actor->id,
                    'metadata' => [
                        'event' => 'apparatus_status_changed',
                        'previous_operational_status' => $previousStatus,
                        'operational_status' => $validated['status'],
                    ],
                ]);
                DB::afterCommit(fn () => $this->sideEffects->ticketChanged($lockedTicket, true));
            }

            return $locked->refresh();
        }, 3);
    }

    /** @return array<string, array<int, mixed>> */
    private function creationRules(): array
    {
        return [
            'client_submission_id' => ['required', 'uuid'],
            'category' => ['required', Rule::in(ApparatusServiceTicketCategory::values())],
            'title' => ['required', 'string', 'min:5', 'max:255'],
            'description' => ['required', 'string', 'min:10', 'max:10000'],
            'priority' => ['required', Rule::in(ApparatusServiceTicketPriority::values())],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $identity
     */
    private function createIdempotently(
        Apparatus $apparatus,
        array $validated,
        array $identity,
        ?User $changedByUser = null,
        ?Employee $changedByEmployee = null,
    ): ApparatusServiceTicketSubmissionResult {
        $clientId = (string) $validated['client_submission_id'];
        $existing = $this->existing($clientId);
        if ($existing !== null) {
            return new ApparatusServiceTicketSubmissionResult($this->load($existing), false);
        }

        try {
            return DB::transaction(function () use ($apparatus, $validated, $identity, $changedByUser, $changedByEmployee, $clientId): ApparatusServiceTicketSubmissionResult {
                $existing = $this->existing($clientId, true);
                if ($existing !== null) {
                    return new ApparatusServiceTicketSubmissionResult($this->load($existing), false);
                }

                $lockedApparatus = Apparatus::query()->lockForUpdate()->findOrFail($apparatus->id);
                $status = (string) $identity['status'];
                $ticket = ApparatusServiceTicket::query()->create(array_merge($identity, [
                    'client_submission_id' => $clientId,
                    'apparatus_id' => $lockedApparatus->id,
                    'station_id' => $lockedApparatus->station_id,
                    'unit_designation_snapshot' => $this->unitLabel($lockedApparatus),
                    'category' => $validated['category'],
                    'title' => trim((string) $validated['title']),
                    'description' => trim((string) $validated['description']),
                    'priority' => $validated['priority'],
                    'service_type' => $validated['service_type'] ?? null,
                    'scheduled_for' => $validated['scheduled_for'] ?? null,
                    'scheduled_location' => $this->nullableTrim($validated['scheduled_location'] ?? null),
                    'expected_return_at' => $validated['expected_return_at'] ?? null,
                    'assigned_to_user_id' => $validated['assigned_to_user_id'] ?? null,
                    'assigned_vendor' => $this->nullableTrim($validated['assigned_vendor'] ?? null),
                    'current_public_response' => $this->nullableTrim($validated['public_note'] ?? null) ?? 'Service ticket submitted.',
                    'acknowledged_at' => $status === ApparatusServiceTicketStatus::Acknowledged->value ? now() : null,
                    'started_at' => $status === ApparatusServiceTicketStatus::InProgress->value ? now() : null,
                    'opened_engine_hours' => $lockedApparatus->current_engine_hours,
                    'opened_miles' => $lockedApparatus->current_miles,
                    'metadata' => ['source' => $identity['origin'].'_service_ticket'],
                ]));
                $ticket->updates()->create([
                    'status' => $status,
                    'public_note' => $this->nullableTrim($validated['public_note'] ?? null) ?? 'Service ticket submitted.',
                    'internal_note' => $this->nullableTrim($validated['internal_note'] ?? null),
                    'scheduled_for' => $validated['scheduled_for'] ?? null,
                    'changed_by_user_id' => $changedByUser?->id,
                    'changed_by_employee_id' => $changedByEmployee?->id,
                    'metadata' => ['event' => 'submitted'],
                ]);
                DB::afterCommit(fn () => $this->sideEffects->ticketCreated($ticket));

                return new ApparatusServiceTicketSubmissionResult($this->load($ticket), true);
            }, 3);
        } catch (QueryException $exception) {
            $existing = $this->existing($clientId);
            if ($existing !== null) {
                return new ApparatusServiceTicketSubmissionResult($this->load($existing), false);
            }
            throw $exception;
        }
    }

    private function existing(string $clientId, bool $lock = false): ?ApparatusServiceTicket
    {
        $query = ApparatusServiceTicket::query()->where('client_submission_id', $clientId);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function load(ApparatusServiceTicket $ticket): ApparatusServiceTicket
    {
        return $ticket->fresh([
            'apparatus:id,station_id,designation,vehicle_number,name,slug,status',
            'station:id,station_number',
            'requestedByEmployee:id,name,rank',
            'createdBy:id,name',
            'assignedTo:id,name',
            'updates.changedByUser:id,name',
        ]) ?? $ticket;
    }

    private function unitLabel(Apparatus $apparatus): string
    {
        return trim((string) ($apparatus->designation ?: $apparatus->vehicle_number ?: $apparatus->name ?: $apparatus->getAttribute('unit_id')));
    }

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }
}
