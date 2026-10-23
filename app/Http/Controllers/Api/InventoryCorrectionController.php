<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Apparatus;
use App\Models\User;
use App\Services\Identity\AuthenticatedMemberContextResolver;
use App\Services\Inventory\InventoryCorrectionConflict;
use App\Services\Inventory\InventoryCorrectionReason;
use App\Services\Inventory\InventoryCorrectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Daily Checkout: a member reports that a checklist entry does not match the apparatus
 * (docs/apparatus-inventory-corrections.md section 3). Offline replays are idempotent.
 */
final class InventoryCorrectionController extends Controller
{
    public function store(
        Request $request,
        int $apparatus,
        AuthenticatedMemberContextResolver $memberContextResolver,
        InventoryCorrectionService $corrections,
    ): JsonResponse {
        $actor = $memberContextResolver->resolve($request)->actor();
        $employee = $actor->requireEmployee();
        $reasons = InventoryCorrectionReason::all();
        $validated = $request->validate([
            'client_submission_id' => ['required', 'uuid'],
            'checklist_version' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/i'],
            'reason' => ['required', 'string', Rule::in($reasons)],
            'compartment_id' => ['required', 'string', 'max:128'],
            'item_id' => ['required_unless:reason,'.InventoryCorrectionReason::NEW_ITEM, 'prohibited_if:reason,'.InventoryCorrectionReason::NEW_ITEM, 'nullable', 'string', 'max:128'],
            'proposal' => ['nullable', 'array:to_compartment_id,to_location_text,name,manufacturer,model,description,quantity'],
            'proposal.to_compartment_id' => ['nullable', 'string', 'max:128'],
            'proposal.to_location_text' => ['nullable', 'string', 'max:200'],
            'proposal.name' => ['required_if:reason,'.InventoryCorrectionReason::NEW_ITEM, 'nullable', 'string', 'max:160', 'regex:/\S/'],
            'proposal.manufacturer' => ['nullable', 'string', 'max:120'],
            'proposal.model' => ['nullable', 'string', 'max:120'],
            'proposal.description' => ['nullable', 'string', 'max:500'],
            'proposal.quantity' => ['required_if:reason,'.InventoryCorrectionReason::WRONG_QUANTITY, 'nullable', 'integer', 'min:1', 'max:999'],
            'note' => ['required_if:reason,'.InventoryCorrectionReason::OTHER, 'nullable', 'string', 'max:1000'],
            'photo' => ['nullable', 'string', 'max:7000000'],
            'inspection_draft_id' => ['nullable', 'uuid'],
        ], [
            'proposal.name.required_if' => 'Give the unlisted item a name.',
            'proposal.quantity.required_if' => 'Enter the quantity actually carried.',
            'note.required_if' => 'Describe what is wrong with this checklist entry.',
        ]);
        if ($validated['reason'] === InventoryCorrectionReason::MOVED
            && blank($validated['proposal']['to_compartment_id'] ?? null)
            && blank($validated['proposal']['to_location_text'] ?? null)) {
            return response()->json([
                'message' => 'Choose the compartment it is in now, or describe where it is.',
                'errors' => ['proposal.to_compartment_id' => ['Choose the compartment it is in now, or describe where it is.']],
            ], 422);
        }

        $model = Apparatus::query()->findOrFail($apparatus);
        if (! $model->isDailyCheckoutRequired()) {
            return response()->json(['message' => 'This apparatus is not configured for Daily Checkout.'], 409);
        }
        $reporter = User::query()->findOrFail($actor->userId());

        try {
            $result = $corrections->submit($reporter, (int) $employee->getKey(), $model, $validated);
        } catch (InventoryCorrectionConflict $conflict) {
            return response()->json([
                'message' => $conflict->getMessage(),
                'code' => $conflict->reason === 'replay_conflict' ? 'INVENTORY_CORRECTION_REPLAY_CONFLICT' : 'INVENTORY_CORRECTION_CHECKLIST_CHANGED',
            ], 409);
        }
        $correction = $result['correction'];

        return response()->json([
            'id' => (int) $correction->getKey(),
            'reference' => $correction->reference(),
            'status' => $correction->status,
            'duplicate' => $result['duplicate'],
        ], $result['duplicate'] ? 200 : 201);
    }
}
