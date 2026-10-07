<?php

declare(strict_types=1);

namespace App\Services\PersonnelRequests;

use App\Models\AssignedEquipment;
use App\Models\Employee;
use App\Models\PersonnelRequest;
use App\Models\PersonnelRequestItem;
use App\Models\Uniform;
use App\Models\User;
use App\Services\UniformInventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PersonnelRequestFulfillmentService
{
    public function __construct(
        private readonly UniformInventoryService $inventory,
        private readonly PersonnelRequestNotifier $notifier,
    ) {}

    public function issueUniform(PersonnelRequestItem $item, Uniform $uniform, User $admin, string $issuedAt, ?string $expiresAt = null, ?string $notes = null, ?int $quantity = null, ?string $idempotencyKey = null): AssignedEquipment
    {
        return $this->issue($item, $admin, $issuedAt, $expiresAt, $notes, $quantity, $idempotencyKey, $uniform);
    }

    public function issueEquipment(PersonnelRequestItem $item, User $admin, string $issuedAt, ?string $expiresAt = null, ?string $notes = null, ?int $quantity = null, ?string $idempotencyKey = null): AssignedEquipment
    {
        return $this->issue($item, $admin, $issuedAt, $expiresAt, $notes, $quantity, $idempotencyKey);
    }

    private function issue(PersonnelRequestItem $item, User $admin, string $issuedAt, ?string $expiresAt, ?string $notes, ?int $quantity, ?string $idempotencyKey, ?Uniform $uniform = null): AssignedEquipment
    {
        return DB::transaction(function () use ($item, $admin, $issuedAt, $expiresAt, $notes, $quantity, $idempotencyKey, $uniform): AssignedEquipment {
            $request = PersonnelRequest::query()->lockForUpdate()->with('beneficiary')->findOrFail($item->personnel_request_id);
            Gate::forUser($admin)->authorize('update', $request);
            if ($request->beneficiary_employee_id !== null) {
                User::query()->where('employee_profile_id', $request->beneficiary_employee_id)->lockForUpdate()->first();
                Employee::query()->whereKey($request->beneficiary_employee_id)->lockForUpdate()->firstOrFail();
            }
            $locked = $request->items()->lockForUpdate()->findOrFail($item->id);
            if (! $request->beneficiary || $locked->category !== ($uniform ? 'uniform' : 'equipment')) {
                throw ValidationException::withMessages(['item' => 'Choose an inventory action appropriate to this beneficiary item.']);
            }

            $key = $idempotencyKey === null ? null : trim($idempotencyKey);
            if ($quantity !== null && blank($key)) {
                throw ValidationException::withMessages(['idempotency_key' => 'An action key is required when issuing a specified quantity.']);
            }
            if ($key !== null && (strlen($key) > 200 || $key === '')) {
                throw ValidationException::withMessages(['idempotency_key' => 'Use a non-empty action key of at most 200 characters.']);
            }
            $fingerprint = hash('sha256', json_encode([$locked->id, $uniform?->id, $admin->id, $quantity, $issuedAt, $expiresAt, $notes], JSON_THROW_ON_ERROR));
            if ($key !== null && ($event = $request->updates()->where('metadata->idempotency_key', $key)->first())) {
                if ($event->event !== 'item_fulfilled' || data_get($event->metadata, 'fingerprint') !== $fingerprint) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This action key was already used for a different action.']);
                }

                return AssignedEquipment::query()->where('source_personnel_request_item_id', $locked->id)->findOrFail(data_get($event->metadata, 'assignment_id'));
            }
            $remaining = $locked->quantity - $locked->fulfilled_quantity;
            if ($quantity === null && $key === null && $remaining === 0 && ($existing = $locked->assignments()->first())) {
                return $existing;
            }
            if ($request->isArchived() || $request->status->isTerminal()) {
                throw ValidationException::withMessages(['item' => 'Only an active request can receive an issue.']);
            }
            $issuedQuantity = $quantity ?? $remaining;
            if ($issuedQuantity < 1 || $issuedQuantity > $remaining) {
                throw ValidationException::withMessages(['quantity' => "Issue between 1 and {$remaining} remaining item(s)."]);
            }

            $assignment = $uniform
                ? $this->inventory->issue($uniform, $request->beneficiary, $issuedQuantity, $issuedAt, $notes, $locked, $expiresAt)
                : AssignedEquipment::query()->create([
                    'user_id' => null,
                    'employee_portal_id' => $request->beneficiary->id,
                    'category' => 'Personnel PPE',
                    'item_description' => $locked->item_name,
                    'quantity' => $issuedQuantity,
                    'issued_at' => $issuedAt,
                    'expires_at' => $expiresAt,
                    'status' => 'active',
                    'source_personnel_request_item_id' => $locked->id,
                    'notes' => $notes,
                ]);
            $fulfilled = $locked->fulfilled_quantity + $issuedQuantity;
            $locked->update([
                'fulfilled_quantity' => $fulfilled,
                'arrived_quantity' => max($locked->arrived_quantity, $fulfilled),
                'fulfillment_status' => $fulfilled === $locked->quantity ? 'fulfilled' : 'partially_fulfilled',
            ]);
            $message = "{$issuedQuantity} × {$locked->item_name} issued ({$fulfilled} of {$locked->quantity}).";
            $request->updates()->create([
                'event' => 'item_fulfilled',
                'status' => $request->status,
                'employee_visible_note' => $message,
                'internal_note' => $notes,
                'changed_by_admin_id' => $admin->id,
                'metadata' => [
                    'item_id' => $locked->id, 'assignment_id' => $assignment->id,
                    'quantity' => $issuedQuantity, 'fulfilled_quantity' => $fulfilled,
                    'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                ],
            ]);
            DB::afterCommit(fn () => $this->notifier->memberUpdated($request, 'item_fulfilled', $message, $locked));

            return $assignment;
        });
    }

    /**
     * @param  array<int, array{item_id: int, quantity: int, uniform_id?: int|null}>  $lines
     * @return array<int, AssignedEquipment>
     */
    public function issueBatch(PersonnelRequest $request, array $lines, User $admin, string $issuedAt, string $idempotencyKey, ?string $expiresAt = null, ?string $notes = null): array
    {
        Validator::make(['lines' => $lines, 'key' => $idempotencyKey], [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.uniform_id' => ['nullable', 'integer', 'min:1'],
            'key' => ['required', 'string', 'max:150'],
        ])->validate();
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            throw ValidationException::withMessages(['idempotency_key' => 'An action key is required for batch issuance.']);
        }
        $lines = array_map(fn (array $line): array => [
            'item_id' => (int) $line['item_id'],
            'quantity' => (int) $line['quantity'],
            'uniform_id' => isset($line['uniform_id']) ? (int) $line['uniform_id'] : null,
        ], $lines);
        usort($lines, fn (array $left, array $right): int => $left['item_id'] <=> $right['item_id']);

        return DB::transaction(function () use ($request, $lines, $admin, $issuedAt, $idempotencyKey, $expiresAt, $notes): array {
            $lockedRequest = PersonnelRequest::query()->lockForUpdate()->findOrFail($request->id);
            Gate::forUser($admin)->authorize('update', $lockedRequest);
            if ($lockedRequest->beneficiary_employee_id !== null) {
                User::query()->where('employee_profile_id', $lockedRequest->beneficiary_employee_id)->lockForUpdate()->first();
                Employee::query()->whereKey($lockedRequest->beneficiary_employee_id)->lockForUpdate()->firstOrFail();
            }
            $fingerprint = hash('sha256', json_encode([$lines, $admin->id, $issuedAt, $expiresAt, $notes], JSON_THROW_ON_ERROR));
            if ($event = $lockedRequest->updates()->where('metadata->idempotency_key', $idempotencyKey)->first()) {
                if ($event->event !== 'items_fulfilled' || data_get($event->metadata, 'fingerprint') !== $fingerprint) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This action key was already used for a different batch.']);
                }

                return array_map(fn (int $id): AssignedEquipment => AssignedEquipment::query()->findOrFail($id), $event->metadata['assignment_ids']);
            }
            $ids = array_column($lines, 'item_id');
            $items = $lockedRequest->items()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($items->count() !== count($lines)) {
                throw ValidationException::withMessages(['items' => 'Every selected item must belong to this request.']);
            }
            $stock = Uniform::query()->whereIn('id', array_filter(array_column($lines, 'uniform_id')))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $assignments = [];
            foreach ($lines as $line) {
                $item = $items->get($line['item_id']);
                $uniform = isset($line['uniform_id']) ? $stock->get($line['uniform_id']) : null;
                if ($item->category === 'uniform' && ! $uniform) {
                    throw ValidationException::withMessages(['uniform_id' => 'Choose inventory for every selected uniform item.']);
                }
                if ($item->category === 'equipment' && $line['uniform_id'] !== null) {
                    throw ValidationException::withMessages(['uniform_id' => 'PPE items use their equipment issue action.']);
                }
                $key = 'batch:'.$idempotencyKey.':item:'.$item->id;
                $assignments[] = $this->issue($item, $admin, $issuedAt, $expiresAt, $notes, (int) $line['quantity'], $key, $uniform);
            }
            $lockedRequest->updates()->create([
                'event' => 'items_fulfilled',
                'status' => $lockedRequest->status,
                'changed_by_admin_id' => $admin->id,
                'metadata' => [
                    'idempotency_key' => $idempotencyKey, 'fingerprint' => $fingerprint,
                    'item_ids' => $ids,
                    'assignment_ids' => array_map(fn (AssignedEquipment $assignment): int => $assignment->id, $assignments),
                ],
            ]);

            return $assignments;
        });
    }

    public function retire(AssignedEquipment $assignment, User $admin, string $returnedAt, string $reason, string $status = 'returned'): AssignedEquipment
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['retirement_reason' => 'A return or retirement reason is required.']);
        }
        if (! in_array($status, ['returned', 'retired'], true)) {
            throw ValidationException::withMessages(['status' => 'Select Returned or Retired.']);
        }

        return DB::transaction(function () use ($assignment, $admin, $returnedAt, $reason, $status): AssignedEquipment {
            $locked = AssignedEquipment::query()->lockForUpdate()->findOrFail($assignment->id);
            $locked->update([
                'status' => $status,
                'returned_at' => $returnedAt,
                'retired_by_id' => $admin->id,
                'retirement_reason' => trim($reason),
            ]);

            return $locked;
        });
    }
}
