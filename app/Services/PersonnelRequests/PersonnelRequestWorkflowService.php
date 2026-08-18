<?php

declare(strict_types=1);

namespace App\Services\PersonnelRequests;

use App\Enums\PersonnelRequestStatus;
use App\Models\Employee;
use App\Models\PersonnelRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PersonnelRequestWorkflowService
{
    private const TRANSITIONS = [
        'pending' => ['acknowledged', 'needs_information', 'ready_for_pickup', 'denied', 'cancelled'],
        'acknowledged' => ['needs_information', 'ordered', 'arrived', 'ready_for_pickup', 'denied', 'cancelled'],
        'needs_information' => ['acknowledged', 'denied', 'cancelled'],
        'ordered' => ['arrived', 'ready_for_pickup', 'denied', 'cancelled'],
        'arrived' => ['ready_for_pickup', 'completed'],
        'ready_for_pickup' => ['completed'],
        'completed' => [],
        'denied' => [],
        'cancelled' => [],
    ];

    public function __construct(private readonly PersonnelRequestNotifier $notifier) {}

    public function canTransition(PersonnelRequest $request, PersonnelRequestStatus $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$request->status->value], true);
    }

    public function transition(
        PersonnelRequest $request,
        PersonnelRequestStatus $to,
        User $actor,
        ?string $employeeVisibleNote = null,
        ?string $internalNote = null,
        array $metadata = [],
    ): PersonnelRequest {
        return DB::transaction(function () use ($request, $to, $actor, $employeeVisibleNote, $internalNote, $metadata): PersonnelRequest {
            $locked = PersonnelRequest::query()->lockForUpdate()->findOrFail($request->id);
            if (! in_array($to->value, self::TRANSITIONS[$locked->status->value], true)) {
                throw ValidationException::withMessages(['status' => "Cannot move {$locked->status->label()} to {$to->label()}."]);
            }
            if ($to === PersonnelRequestStatus::Completed && $locked->items()->where('fulfillment_status', '!=', 'fulfilled')->exists()) {
                throw ValidationException::withMessages(['status' => 'Fulfill every requested item before completing the request.']);
            }

            $locked->fill([
                'status' => $to,
                'employee_response' => $employeeVisibleNote ?? $locked->employee_response,
                'admin_status_detail' => $internalNote ?? $locked->admin_status_detail,
                'assigned_admin_id' => $locked->assigned_admin_id ?? $actor->id,
            ]);
            if ($to === PersonnelRequestStatus::NeedsInformation && isset($metadata['information_requested'])) {
                $locked->information_requested = $metadata['information_requested'];
            }
            if ($to === PersonnelRequestStatus::Acknowledged && $locked->acknowledged_at === null) {
                $locked->acknowledged_by_id = $actor->id;
                $locked->acknowledged_at = now();
            }
            foreach (['Completed' => 'completed_at', 'Denied' => 'denied_at', 'Cancelled' => 'cancelled_at'] as $case => $column) {
                if ($to === constant(PersonnelRequestStatus::class.'::'.$case)) {
                    $locked->{$column} = now();
                }
            }
            $locked->save();
            $locked->updates()->create([
                'event' => 'status_changed',
                'status' => $to,
                'employee_visible_note' => $employeeVisibleNote,
                'internal_note' => $internalNote,
                'changed_by_admin_id' => $actor->id,
                'metadata' => $metadata ?: null,
            ]);

            DB::afterCommit(fn () => $this->notifier->statusChanged($locked));

            return $locked->fresh(['items', 'updates']);
        });
    }

    public function requestInformation(PersonnelRequest $request, User $actor, array $types, string $message, ?string $internalNote = null): PersonnelRequest
    {
        $types = array_values(array_intersect($types, ['police_report', 'police_case_number', 'damage_photo', 'additional_explanation', 'other']));
        if ($types === [] || trim($message) === '') {
            throw ValidationException::withMessages(['information_requested' => 'Select the information needed and provide instructions.']);
        }

        return $this->transition($request, PersonnelRequestStatus::NeedsInformation, $actor, $message, $internalNote, ['information_requested' => $types]);
    }

    public function employeeRespond(PersonnelRequest $request, Employee $employee, string $message): PersonnelRequest
    {
        if ($request->beneficiary_employee_id !== $employee->id || ! $this->canSupplyRequestedInformation($request)) {
            abort(403);
        }
        if (trim($message) === '') {
            throw ValidationException::withMessages(['response' => 'A response is required.']);
        }

        return DB::transaction(function () use ($request, $employee, $message): PersonnelRequest {
            $locked = PersonnelRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($locked->beneficiary_employee_id !== $employee->id || ! $this->canSupplyRequestedInformation($locked)) {
                abort(403);
            }
            $locked->status = PersonnelRequestStatus::Acknowledged;
            $locked->acknowledged_at ??= now();
            $locked->save();
            $locked->updates()->create([
                'event' => 'employee_responded',
                'status' => PersonnelRequestStatus::Acknowledged,
                'employee_visible_note' => trim($message),
                'changed_by_employee_id' => $employee->id,
            ]);
            DB::afterCommit(fn () => $this->notifier->employeeResponded($locked));

            return $locked->fresh(['updates']);
        });
    }

    private function canSupplyRequestedInformation(PersonnelRequest $request): bool
    {
        return in_array($request->status, [PersonnelRequestStatus::NeedsInformation, PersonnelRequestStatus::Acknowledged], true)
            && filled($request->information_requested);
    }

    public function addNote(PersonnelRequest $request, User $actor, ?string $employeeVisibleNote, ?string $internalNote): PersonnelRequest
    {
        if (blank($employeeVisibleNote) && blank($internalNote)) {
            throw ValidationException::withMessages(['note' => 'Enter an employee-visible or internal note.']);
        }

        return DB::transaction(function () use ($request, $actor, $employeeVisibleNote, $internalNote): PersonnelRequest {
            $locked = PersonnelRequest::query()->lockForUpdate()->findOrFail($request->id);
            if (filled($employeeVisibleNote)) {
                $locked->employee_response = trim($employeeVisibleNote);
            }
            if (filled($internalNote)) {
                $locked->admin_status_detail = trim($internalNote);
            }
            $locked->save();
            $locked->updates()->create([
                'event' => 'note_added',
                'status' => $locked->status,
                'employee_visible_note' => filled($employeeVisibleNote) ? trim($employeeVisibleNote) : null,
                'internal_note' => filled($internalNote) ? trim($internalNote) : null,
                'changed_by_admin_id' => $actor->id,
            ]);

            return $locked->fresh(['updates']);
        });
    }
}
