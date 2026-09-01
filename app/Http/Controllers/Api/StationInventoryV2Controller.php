<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Models\StationInventoryAudit;
use App\Models\StationInventoryItem;
use App\Models\StationSupplyRequest;
use App\Services\Identity\AuthenticatedMemberContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;

/**
 * Station Inventory V2 API Controller
 *
 * Provides PIN-protected access to station inventory management.
 * Uses signed URLs for session-based authentication after PIN verification.
 */
class StationInventoryV2Controller extends Controller
{
    private function signedShift(Request $request): string
    {
        $shift = $request->query('shift_context', $request->query('actor_shift', 'Unknown'));

        return is_string($shift) && $shift !== '' ? $shift : 'Unknown';
    }

    /**
     * Verify station PIN and generate access token
     *
     * POST /api/v2/station-inventory/verify-pin
     */
    public function verifyPin(Request $request, AuthenticatedMemberContextResolver $memberContextResolver): JsonResponse
    {
        $actor = $memberContextResolver->resolve($request)->actor();
        $employee = $actor->requireEmployee();
        $validator = Validator::make($request->all(), [
            'station_id' => 'required|integer',
            'pin' => 'required|string|size:4',
            'actor_shift' => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Try to find station by ID first, then by station_number
        $station = Station::find($request->station_id);
        if (! $station) {
            $station = Station::where('station_number', $request->station_id)->first();
        }

        if (! $station) {
            return response()->json([
                'success' => false,
                'message' => 'Station not found',
            ], 404);
        }

        // Verify PIN using Hash::check
        if (! Hash::check($request->pin, $station->inventory_pin_hash)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid PIN',
            ], 401);
        }

        // Create audit log for PIN verification
        StationInventoryAudit::create([
            'station_id' => $station->id,
            'inventory_item_id' => null,
            'actor_user_id' => $actor->userId(),
            'actor_employee_id' => $employee->getKey(),
            'actor_name' => $employee->name,
            'actor_shift' => $request->actor_shift,
            'action' => 'pin_verified',
            'from_value' => null,
            'to_value' => null,
        ]);

        // Generate signed URLs (expires in 4 hours)
        $urlParams = [
            'stationId' => $station->id,
            'shift_context' => $request->actor_shift,
        ];

        $inventoryUrl = URL::temporarySignedRoute(
            'api.v2.station-inventory.access',
            now()->addHours(4),
            $urlParams
        );

        $supplyRequestsUrl = URL::temporarySignedRoute(
            'api.v2.station-inventory.supply-requests',
            now()->addHours(4),
            $urlParams
        );

        return response()->json([
            'success' => true,
            'station_id' => $station->id,  // Canonical PK for any subsequent operations
            'station' => [
                'id' => $station->id,
                'name' => $station->name,
                'station_number' => $station->station_number,
                'address' => $station->address,
            ],
            // Return absolute signed URLs - frontend should use these as-is
            'inventory_url' => $inventoryUrl,
            'supply_requests_url' => $supplyRequestsUrl,
        ]);
    }

    /**
     * Get full inventory list for a station
     *
     * GET /api/v2/station-inventory/{stationId}
     */
    public function getInventory(Request $request, int $stationId): JsonResponse
    {
        $station = Station::findOrFail($stationId);

        // Load station inventory items with relationships
        $inventoryItems = StationInventoryItem::where('station_id', $stationId)
            ->with(['inventoryItem.category'])
            ->get();

        // Group by category
        $groupedInventory = $inventoryItems->groupBy(function ($item) {
            return $item->inventoryItem->category->name ?? 'Uncategorized';
        })->map(function ($items, $categoryName) {
            return [
                'category' => $categoryName,
                'items' => $items->map(function ($stationItem) {
                    return [
                        'id' => $stationItem->id,
                        'inventory_item_id' => $stationItem->inventory_item_id,
                        'name' => $stationItem->inventoryItem->name,
                        'sku' => $stationItem->inventoryItem->sku,
                        'unit_label' => $stationItem->inventoryItem->unit_label,
                        'par_quantity' => $stationItem->inventoryItem->par_quantity,
                        'par_units' => $stationItem->inventoryItem->par_units,
                        'on_hand' => $stationItem->on_hand,
                        'status' => $stationItem->status,
                        'last_updated_at' => $stationItem->last_updated_at?->toISOString(),
                    ];
                })->values(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'station' => [
                'id' => $station->id,
                'name' => $station->name,
                'station_number' => $station->station_number,
            ],
            'inventory' => $groupedInventory,
        ]);
    }

    /**
     * Update on_hand count for a station inventory item
     *
     * PUT /api/v2/station-inventory/{stationId}/item/{itemId}
     */
    public function updateItem(
        Request $request,
        int $stationId,
        int $itemId,
        AuthenticatedMemberContextResolver $memberContextResolver,
    ): JsonResponse {
        $actor = $memberContextResolver->resolve($request)->actor();
        $employee = $actor->requireEmployee();

        $validator = Validator::make($request->all(), [
            'on_hand' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // The client receives the StationInventoryItem primary key as `item.id`.
        // Scope it to the station before updating so IDs cannot cross stations.
        $stationItem = StationInventoryItem::where('station_id', $stationId)
            ->whereKey($itemId)
            ->with('inventoryItem')
            ->firstOrFail();

        // Store old values for audit log
        $oldValues = [
            'on_hand' => $stationItem->on_hand,
            'status' => $stationItem->status,
        ];

        // Update on_hand
        $stationItem->on_hand = $request->on_hand;
        $stationItem->last_updated_at = now();

        // Low-stock detection logic
        $parQuantity = $stationItem->inventoryItem->par_quantity;
        $lowStockThreshold = (int) floor($parQuantity / 2);

        if ($request->on_hand <= $lowStockThreshold && $stationItem->status !== 'ordered') {
            $stationItem->status = 'low';
        } elseif ($request->on_hand > $lowStockThreshold && $stationItem->status === 'low') {
            $stationItem->status = 'ok';
        }

        $stationItem->save();

        // Create audit log
        StationInventoryAudit::create([
            'station_id' => $stationId,
            'inventory_item_id' => $stationItem->inventory_item_id,
            'actor_user_id' => $actor->userId(),
            'actor_employee_id' => $employee->getKey(),
            'actor_name' => $employee->name,
            'actor_shift' => $this->signedShift($request),
            'action' => 'count_updated',
            'from_value' => $oldValues,
            'to_value' => [
                'on_hand' => $stationItem->on_hand,
                'status' => $stationItem->status,
            ],
        ]);

        return response()->json([
            'success' => true,
            'item' => [
                'id' => $stationItem->id,
                'inventory_item_id' => $stationItem->inventory_item_id,
                'name' => $stationItem->inventoryItem->name,
                'sku' => $stationItem->inventoryItem->sku,
                'unit_label' => $stationItem->inventoryItem->unit_label,
                'par_quantity' => $stationItem->inventoryItem->par_quantity,
                'par_units' => $stationItem->inventoryItem->par_units,
                'on_hand' => $stationItem->on_hand,
                'status' => $stationItem->status,
                'last_updated_at' => $stationItem->last_updated_at?->toISOString(),
            ],
        ]);
    }

    /**
     * Get all open/ordered/denied supply requests for a station
     *
     * GET /api/v2/station-inventory/{stationId}/supply-requests
     */
    public function getSupplyRequests(Request $request, int $stationId): JsonResponse
    {
        $requests = StationSupplyRequest::where('station_id', $stationId)
            ->whereIn('status', ['open', 'ordered', 'denied'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($req) {
                return [
                    'id' => $req->id,
                    'request_text' => $req->request_text,
                    'status' => $req->status,
                    'created_by_name' => $req->created_by_name,
                    'created_by_shift' => $req->created_by_shift,
                    'created_at' => $req->created_at->toISOString(),
                ];
            });

        return response()->json([
            'success' => true,
            'requests' => $requests,
        ]);
    }

    /**
     * Create a new supply request
     *
     * POST /api/v2/station-inventory/{stationId}/supply-requests
     */
    public function createSupplyRequest(
        Request $request,
        int $stationId,
        AuthenticatedMemberContextResolver $memberContextResolver,
    ): JsonResponse {
        $actor = $memberContextResolver->resolve($request)->actor();
        $employee = $actor->requireEmployee();

        $validator = Validator::make($request->all(), [
            'request_text' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Create supply request
        $supplyRequest = StationSupplyRequest::create([
            'station_id' => $stationId,
            'actor_user_id' => $actor->userId(),
            'actor_employee_id' => $employee->getKey(),
            'request_text' => $request->request_text,
            'status' => 'open',
            'created_by_name' => $employee->name,
            'created_by_shift' => $this->signedShift($request),
        ]);

        // Create audit log
        StationInventoryAudit::create([
            'station_id' => $stationId,
            'inventory_item_id' => null,
            'actor_user_id' => $actor->userId(),
            'actor_employee_id' => $employee->getKey(),
            'actor_name' => $employee->name,
            'actor_shift' => $this->signedShift($request),
            'action' => 'note_added',
            'from_value' => null,
            'to_value' => [
                'request_text' => $request->request_text,
                'request_id' => $supplyRequest->id,
            ],
        ]);

        return response()->json([
            'success' => true,
            'request' => [
                'id' => $supplyRequest->id,
                'request_text' => $supplyRequest->request_text,
                'status' => $supplyRequest->status,
                'created_by_name' => $supplyRequest->created_by_name,
                'created_by_shift' => $supplyRequest->created_by_shift,
                'created_at' => $supplyRequest->created_at->toISOString(),
            ],
        ]);
    }
}
