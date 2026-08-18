<?php

declare(strict_types=1);

namespace App\Services\PersonnelRequests;

use App\Models\AssignedEquipment;
use App\Models\PersonnelRequestItem;
use App\Models\Uniform;
use App\Models\User;
use App\Services\UniformInventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PersonnelRequestFulfillmentService
{
    public function __construct(private readonly UniformInventoryService $inventory) {}

    public function issueUniform(PersonnelRequestItem $item, Uniform $uniform, User $admin, string $issuedAt, ?string $expiresAt = null, ?string $notes = null): AssignedEquipment
    {
        return DB::transaction(function () use ($item, $uniform, $admin, $issuedAt, $expiresAt, $notes): AssignedEquipment {
            $locked = PersonnelRequestItem::query()->lockForUpdate()->with('request.beneficiary')->findOrFail($item->id);
            if ($locked->category !== 'uniform' || ! $locked->request->beneficiary) {
                throw ValidationException::withMessages(['item' => 'Only a beneficiary uniform item can be issued from uniform inventory.']);
            }
            if ($existing = AssignedEquipment::query()->where('source_personnel_request_item_id', $locked->id)->first()) {
                return $existing;
            }

            $assignment = $this->inventory->issue(
                $uniform,
                $locked->request->beneficiary,
                $locked->quantity,
                $issuedAt,
                $notes,
                $locked,
                $expiresAt,
            );
            $locked->update(['fulfillment_status' => 'fulfilled', 'fulfilled_quantity' => $locked->quantity]);
            $locked->request->updates()->create([
                'event' => 'item_fulfilled',
                'status' => $locked->request->status,
                'internal_note' => "{$locked->item_name} issued from uniform inventory.",
                'changed_by_admin_id' => $admin->id,
                'metadata' => ['item_id' => $locked->id, 'assignment_id' => $assignment->id],
            ]);

            return $assignment;
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

    public function issueEquipment(PersonnelRequestItem $item, User $admin, string $issuedAt, ?string $expiresAt = null, ?string $notes = null): AssignedEquipment
    {
        return DB::transaction(function () use ($item, $admin, $issuedAt, $expiresAt, $notes): AssignedEquipment {
            $locked = PersonnelRequestItem::query()->lockForUpdate()->with('request.beneficiary')->findOrFail($item->id);
            if ($locked->category !== 'equipment' || ! $locked->request->beneficiary) {
                throw ValidationException::withMessages(['item' => 'Only a beneficiary PPE item can be issued through this action.']);
            }
            if ($existing = AssignedEquipment::query()->where('source_personnel_request_item_id', $locked->id)->first()) {
                return $existing;
            }

            $assignment = AssignedEquipment::query()->create([
                'user_id' => null,
                'employee_portal_id' => $locked->request->beneficiary->id,
                'category' => 'Personnel PPE',
                'item_description' => $locked->item_name,
                'quantity' => $locked->quantity,
                'issued_at' => $issuedAt,
                'expires_at' => $expiresAt,
                'status' => 'active',
                'source_personnel_request_item_id' => $locked->id,
                'notes' => $notes,
            ]);
            $locked->update(['fulfillment_status' => 'fulfilled', 'fulfilled_quantity' => $locked->quantity]);
            $locked->request->updates()->create([
                'event' => 'item_fulfilled',
                'status' => $locked->request->status,
                'internal_note' => "{$locked->item_name} assigned to the beneficiary.",
                'changed_by_admin_id' => $admin->id,
                'metadata' => ['item_id' => $locked->id, 'assignment_id' => $assignment->id],
            ]);

            return $assignment;
        });
    }
}
