<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AssignedEquipment;
use App\Models\Employee;
use App\Models\Uniform;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UniformInventoryService
{
    public function issue(
        Uniform $uniform,
        Employee $employee,
        int $quantity,
        string $issuedAt,
        ?string $notes = null,
    ): AssignedEquipment {
        if ($quantity < 1) {
            throw ValidationException::withMessages([
                'quantity' => 'The issue quantity must be at least one.',
            ]);
        }

        return DB::transaction(function () use ($uniform, $employee, $quantity, $issuedAt, $notes): AssignedEquipment {
            $inventory = Uniform::query()->lockForUpdate()->findOrFail($uniform->getKey());

            if ($inventory->quantity_on_hand < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => "Only {$inventory->quantity_on_hand} item(s) are available.",
                ]);
            }

            $description = $inventory->item_name;
            if (filled($inventory->size)) {
                $description .= " — Size {$inventory->size}";
            }

            $assignment = AssignedEquipment::create([
                'user_id' => null,
                'employee_portal_id' => $employee->getKey(),
                'uniform_id' => $inventory->getKey(),
                'category' => 'Uniform Inventory',
                'item_description' => $description,
                'quantity' => $quantity,
                'issued_at' => $issuedAt,
                'notes' => $notes,
            ]);

            $inventory->decrement('quantity_on_hand', $quantity);

            return $assignment;
        });
    }
}
