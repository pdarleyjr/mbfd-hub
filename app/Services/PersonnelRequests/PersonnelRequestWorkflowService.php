<?php

declare(strict_types=1);

namespace App\Services\PersonnelRequests;

use App\Enums\PersonnelRequestStatus;
use App\Models\Employee;
use App\Models\PersonnelRequest;
use App\Models\PersonnelRequestItem;
use App\Models\PersonnelRequestUpdate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
            Gate::forUser($actor)->authorize('update', $locked);
            if ($locked->isArchived()) {
                throw ValidationException::withMessages(['status' => 'Archived requests cannot change status.']);
            }
            if (! in_array($to->value, self::TRANSITIONS[$locked->status->value], true)) {
                throw ValidationException::withMessages(['status' => "Cannot move {$locked->status->label()} to {$to->label()}."]);
            }
            if ($to === PersonnelRequestStatus::Completed && $locked->items()->where('fulfillment_status', '!=', 'fulfilled')->exists()) {
                throw ValidationException::withMessages(['status' => 'Fulfill every requested item before completing the request.']);
            }
            if ($to === PersonnelRequestStatus::Arrived) {
                foreach ($locked->items()->orderBy('id')->lockForUpdate()->get() as $item) {
                    $item->update([
                        'arrived_quantity' => max($item->quantity, $item->fulfilled_quantity),
                        'fulfillment_status' => $item->fulfilled_quantity >= $item->quantity ? 'fulfilled'
                            : ($item->fulfilled_quantity > 0 ? 'partially_fulfilled' : 'arrived'),
                    ]);
                }
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

    public function employeeRespond(PersonnelRequest $request, Employee $employee, string $message, ?PersonnelRequestItem $item = null, ?string $idempotencyKey = null): PersonnelRequest
    {
        if ($request->beneficiary_employee_id !== $employee->id) {
            abort(403);
        }
        if (trim($message) === '') {
            throw ValidationException::withMessages(['response' => 'A response is required.']);
        }

        return DB::transaction(function () use ($request, $employee, $message, $item, $idempotencyKey): PersonnelRequest {
            $locked = PersonnelRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($locked->beneficiary_employee_id !== $employee->id) {
                abort(403);
            }
            $lockedItem = $item ? $this->lockItem($locked, $item) : null;
            $message = trim($message);
            $metadata = $this->actionMetadata($idempotencyKey, [$employee->id, $lockedItem?->id, $message], $lockedItem);
            if ($this->existingAction($locked, 'employee_responded', $metadata)) {
                return $locked->fresh(['items', 'updates']);
            }
            if ($locked->isArchived() || $locked->status->isTerminal()) {
                abort(403);
            }
            $informationResponse = $lockedItem === null && ($locked->status === PersonnelRequestStatus::NeedsInformation
                || ($locked->status === PersonnelRequestStatus::Acknowledged && filled($locked->information_requested)));
            if ($informationResponse && $locked->status === PersonnelRequestStatus::NeedsInformation) {
                $locked->status = PersonnelRequestStatus::Acknowledged;
                $locked->acknowledged_at ??= now();
            }
            $locked->save();
            $locked->updates()->create([
                'event' => 'employee_responded',
                'status' => $locked->status,
                'employee_visible_note' => $message,
                'changed_by_employee_id' => $employee->id,
                'metadata' => $metadata ?: null,
            ]);
            DB::afterCommit(fn () => $informationResponse
                ? $this->notifier->employeeResponded($locked)
                : $this->notifier->adminMessageReceived($locked, $message, $lockedItem));

            return $locked->fresh(['updates']);
        });
    }

    public function addNote(PersonnelRequest $request, User $actor, ?string $employeeVisibleNote, ?string $internalNote, ?PersonnelRequestItem $item = null, ?string $idempotencyKey = null): PersonnelRequest
    {
        if (blank($employeeVisibleNote) && blank($internalNote)) {
            throw ValidationException::withMessages(['note' => 'Enter an employee-visible or internal note.']);
        }

        return DB::transaction(function () use ($request, $actor, $employeeVisibleNote, $internalNote, $item, $idempotencyKey): PersonnelRequest {
            $locked = PersonnelRequest::query()->lockForUpdate()->findOrFail($request->id);
            Gate::forUser($actor)->authorize('update', $locked);
            $lockedItem = $item ? $this->lockItem($locked, $item) : null;
            $employeeVisibleNote = filled($employeeVisibleNote) ? trim($employeeVisibleNote) : null;
            $internalNote = filled($internalNote) ? trim($internalNote) : null;
            $metadata = $this->actionMetadata($idempotencyKey, [$actor->id, $lockedItem?->id, $employeeVisibleNote, $internalNote], $lockedItem);
            if ($this->existingAction($locked, 'note_added', $metadata)) {
                return $locked->fresh(['items', 'updates']);
            }
            if ($locked->isArchived()) {
                throw ValidationException::withMessages(['note' => 'Archived requests cannot receive notes.']);
            }
            if ($lockedItem === null && filled($employeeVisibleNote)) {
                $locked->employee_response = trim($employeeVisibleNote);
            }
            if ($lockedItem === null && filled($internalNote)) {
                $locked->admin_status_detail = trim($internalNote);
            }
            $locked->save();
            $locked->updates()->create([
                'event' => 'note_added',
                'status' => $locked->status,
                'employee_visible_note' => filled($employeeVisibleNote) ? trim($employeeVisibleNote) : null,
                'internal_note' => filled($internalNote) ? trim($internalNote) : null,
                'changed_by_admin_id' => $actor->id,
                'metadata' => $metadata ?: null,
            ]);
            if ($employeeVisibleNote !== null) {
                DB::afterCommit(fn () => $this->notifier->memberUpdated($locked, 'message', $employeeVisibleNote, $lockedItem));
            }

            return $locked->fresh(['updates']);
        });
    }

    public function acknowledgeItem(PersonnelRequestItem $item, User $actor, ?string $message = null, ?string $idempotencyKey = null): PersonnelRequestItem
    {
        return DB::transaction(function () use ($item, $actor, $message, $idempotencyKey): PersonnelRequestItem {
            $request = PersonnelRequest::query()->lockForUpdate()->findOrFail($item->personnel_request_id);
            Gate::forUser($actor)->authorize('update', $request);
            $locked = $this->lockItem($request, $item);
            $message = filled($message) ? trim($message) : "Support Services acknowledged {$locked->item_name}.";
            $metadata = $this->actionMetadata($idempotencyKey, [$actor->id, $locked->id, $message], $locked);
            if ($this->existingAction($request, 'item_acknowledged', $metadata)) {
                return $locked;
            }
            $this->requireActive($request);
            if ($locked->fulfillment_status === 'unfulfilled') {
                $locked->update(['fulfillment_status' => 'acknowledged']);
            }
            $request->updates()->create([
                'event' => 'item_acknowledged', 'status' => $request->status,
                'employee_visible_note' => $message, 'changed_by_admin_id' => $actor->id,
                'metadata' => $metadata,
            ]);
            DB::afterCommit(fn () => $this->notifier->memberUpdated($request, 'item_acknowledged', $message, $locked));

            return $locked;
        });
    }

    public function arriveItem(PersonnelRequestItem $item, User $actor, int $quantity, string $idempotencyKey, ?string $employeeVisibleNote = null, ?string $internalNote = null): PersonnelRequestItem
    {
        if (trim($idempotencyKey) === '') {
            throw ValidationException::withMessages(['idempotency_key' => 'An action key is required when recording arrivals.']);
        }

        return DB::transaction(function () use ($item, $actor, $quantity, $idempotencyKey, $employeeVisibleNote, $internalNote): PersonnelRequestItem {
            $request = PersonnelRequest::query()->lockForUpdate()->findOrFail($item->personnel_request_id);
            Gate::forUser($actor)->authorize('update', $request);
            $locked = $this->lockItem($request, $item);
            $note = filled($employeeVisibleNote) ? trim($employeeVisibleNote) : null;
            $internalNote = filled($internalNote) ? trim($internalNote) : null;
            $metadata = $this->actionMetadata($idempotencyKey, [$actor->id, $locked->id, $quantity, $note, $internalNote], $locked);
            if ($this->existingAction($request, 'item_arrived', $metadata)) {
                return $locked;
            }
            $this->requireActive($request);
            $remaining = $locked->quantity - $locked->arrived_quantity;
            if ($quantity < 1 || $quantity > $remaining) {
                throw ValidationException::withMessages(['quantity' => "Record between 1 and {$remaining} remaining arrival(s)."]);
            }
            $arrived = $locked->arrived_quantity + $quantity;
            $locked->update([
                'arrived_quantity' => $arrived,
                'fulfillment_status' => $locked->fulfilled_quantity >= $locked->quantity ? 'fulfilled'
                    : ($locked->fulfilled_quantity > 0 ? 'partially_fulfilled' : ($arrived === $locked->quantity ? 'arrived' : 'partially_arrived')),
            ]);
            $message = "{$quantity} × {$locked->item_name} arrived ({$arrived} of {$locked->quantity}).";
            if ($note !== null) {
                $message .= ' '.$note;
            }
            $request->updates()->create([
                'event' => 'item_arrived', 'status' => $request->status,
                'employee_visible_note' => $message, 'internal_note' => $internalNote,
                'changed_by_admin_id' => $actor->id,
                'metadata' => $metadata + ['quantity' => $quantity, 'arrived_quantity' => $arrived],
            ]);
            DB::afterCommit(fn () => $this->notifier->memberUpdated($request, 'item_arrived', $message, $locked));

            return $locked;
        });
    }

    private function lockItem(PersonnelRequest $request, PersonnelRequestItem $item): PersonnelRequestItem
    {
        $locked = $request->items()->whereKey($item->id)->lockForUpdate()->first();
        if (! $locked) {
            throw ValidationException::withMessages(['item_id' => 'Select an item from this request.']);
        }

        return $locked;
    }

    private function requireActive(PersonnelRequest $request): void
    {
        if ($request->isArchived() || $request->status->isTerminal()) {
            throw ValidationException::withMessages(['item' => 'Only an active request can receive item updates.']);
        }
    }

    private function actionMetadata(?string $idempotencyKey, array $payload, ?PersonnelRequestItem $item): array
    {
        $metadata = $item ? ['item_id' => $item->id] : [];
        if ($idempotencyKey !== null) {
            $key = trim($idempotencyKey);
            if ($key === '' || strlen($key) > 200) {
                throw ValidationException::withMessages(['idempotency_key' => 'Use a non-empty action key of at most 200 characters.']);
            }
            $metadata += ['idempotency_key' => $key, 'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))];
        }

        return $metadata;
    }

    private function existingAction(PersonnelRequest $request, string $event, array $metadata): ?PersonnelRequestUpdate
    {
        if (! isset($metadata['idempotency_key'])) {
            return null;
        }
        $existing = $request->updates()->where('metadata->idempotency_key', $metadata['idempotency_key'])->first();
        if ($existing && ($existing->event !== $event || data_get($existing->metadata, 'fingerprint') !== $metadata['fingerprint'])) {
            throw ValidationException::withMessages(['idempotency_key' => 'This action key was already used for a different action.']);
        }

        return $existing;
    }
}
