<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Models\Room;
use App\Models\RoomAsset;
use App\Models\RoomAudit;
use App\Models\RoomAuditItem;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class StationController extends Controller
{
    /**
     * List all stations
     */
    public function index(): JsonResponse
    {
        $stations = Station::with(['apparatuses', 'rooms'])
            ->withCount('apparatuses', 'rooms')
            ->get();

        return response()->json([
            'stations' => $stations,
            'total' => $stations->count(),
        ]);
    }

    /**
     * Get a single station with all related data
     */
    public function show(int $id): JsonResponse
    {
        $station = Station::with([
            'apparatuses' => function ($query) {
                $query->with('currentDefects');
            },
            'capitalProjects' => function ($query) {
                $query->active()->orderBy('target_completion_date');
            },
            'under25kProjects' => function ($query) {
                $query->active()->orderBy('target_completion_date');
            },
            'rooms' => function ($query) {
                $query->active()->withCount('assets');
            },
            'rooms.assets' => function ($query) {
                $query->active();
            },
            'shopWorks' => function ($query) {
                $query->active()->orderBy('created_at', 'desc')->limit(10);
            },
        ])
        ->withCount(['apparatuses', 'rooms', 'capitalProjects', 'under25kProjects'])
        ->findOrFail($id);

        // Calculate dorm beds dynamically based on personnel in station
        $dormBedsCount = $station->dormBedsCount;

        // Get room count by type
        $roomsByType = $station->rooms()
            ->select('room_type')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('room_type')
            ->pluck('count', 'room_type')
            ->toArray();

        // Get apparatus summary by type
        $apparatusByType = $station->apparatuses()
            ->select('type')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        // Get project totals
        $projectTotals = [
            'capital_projects' => [
                'active' => $station->capitalProjects()->count(),
                'budget' => $station->capitalProjects()->sum('budget_amount'),
            ],
            'under_25k_projects' => [
                'active' => $station->under25kProjects()->count(),
                'budget' => $station->under25kProjects()->sum('budget_amount'),
            ],
        ];

        return response()->json([
            'station' => $station,
            'summary' => [
                'dorm_beds_count' => $dormBedsCount,
                'rooms_by_type' => $roomsByType,
                'apparatus_by_type' => $apparatusByType,
                'project_totals' => $projectTotals,
            ],
        ]);
    }

    /**
     * Create a new station
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:20',
            'station_number' => 'nullable|string|max:10',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ]);

        $station = Station::create($validated);

        return response()->json($station->load('rooms'), 201);
    }

    /**
     * Update a station
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $station = Station::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:20',
            'station_number' => 'nullable|string|max:10',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ]);

        $station->update($validated);

        return response()->json($station->load('rooms'));
    }

    /**
     * Delete a station
     */
    public function destroy(int $id): JsonResponse
    {
        $station = Station::findOrFail($id);

        // Check for dependencies
        if ($station->apparatuses()->exists()) {
            return response()->json([
                'error' => 'Cannot delete station with assigned apparatuses',
            ], 422);
        }

        if ($station->capitalProjects()->exists()) {
            return response()->json([
                'error' => 'Cannot delete station with assigned capital projects',
            ], 422);
        }

        $station->delete();

        return response()->json(null, 204);
    }

    /**
     * Get rooms for a station
     */
    public function rooms(int $id): JsonResponse
    {
        $station = Station::findOrFail($id);

        $rooms = $station->rooms()
            ->withCount('assets')
            ->orderBy('floor')
            ->orderBy('name')
            ->get();

        return response()->json([
            'station_id' => $id,
            'rooms' => $rooms,
            'total' => $rooms->count(),
        ]);
    }

    /**
     * Create a room for a station
     */
    public function storeRoom(Request $request, int $id): JsonResponse
    {
        $station = Station::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'floor' => 'nullable|string|max:50',
            'room_type' => 'required|string|max:100',
            'description' => 'nullable|string',
            'capacity' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ]);

        $validated['station_id'] = $id;

        $room = Room::create($validated);

        return response()->json($room, 201);
    }

    /**
     * Get assets for a room
     */
    public function roomAssets(int $stationId, int $roomId): JsonResponse
    {
        $room = Room::where('station_id', $stationId)->findOrFail($roomId);

        $assets = $room->assets()
            ->active()
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return response()->json([
            'room_id' => $roomId,
            'assets' => $assets,
            'total' => $assets->count(),
        ]);
    }

    /**
     * Create an asset for a room
     */
    public function storeRoomAsset(Request $request, int $stationId, int $roomId): JsonResponse
    {
        $room = Room::where('station_id', $stationId)->findOrFail($roomId);

        $validated = $request->validate([
            'asset_tag' => 'nullable|string|max:100',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category' => 'required|string|max:100',
            'quantity' => 'nullable|integer|min:1',
            'unit' => 'nullable|string|max:20',
            'condition' => 'nullable|string|max:50',
            'purchase_date' => 'nullable|date',
            'purchase_price' => 'nullable|numeric',
            'serial_number' => 'nullable|string|max:255',
            'manufacturer' => 'nullable|string|max:255',
            'model_number' => 'nullable|string|max:255',
            'location_within_room' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ]);

        $validated['room_id'] = $roomId;

        $asset = RoomAsset::create($validated);

        return response()->json($asset, 201);
    }

    /**
     * Get audits for a room
     */
    public function roomAudits(int $stationId, int $roomId): JsonResponse
    {
        $room = Room::where('station_id', $stationId)->findOrFail($roomId);

        $audits = $room->audits()
            ->with(['auditor', 'items'])
            ->orderBy('scheduled_date', 'desc')
            ->get();

        return response()->json([
            'room_id' => $roomId,
            'audits' => $audits,
            'total' => $audits->count(),
        ]);
    }

    /**
     * Create an audit for a room
     */
    public function storeRoomAudit(Request $request, int $stationId, int $roomId): JsonResponse
    {
        $room = Room::where('station_id', $stationId)->findOrFail($roomId);

        $validated = $request->validate([
            'auditor_id' => 'nullable|exists:users,id',
            'audit_type' => 'required|string|max:50',
            'scheduled_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $validated['room_id'] = $roomId;
        $validated['status'] = 'Pending';

        $audit = RoomAudit::create($validated);

        return response()->json($audit->load('auditor'), 201);
    }

    /**
     * Complete an audit with items
     */
    public function completeAudit(Request $request, int $stationId, int $roomId, int $auditId): JsonResponse
    {
        $audit = RoomAudit::where('room_id', $roomId)->findOrFail($auditId);

        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.room_asset_id' => 'nullable|exists:room_assets,id',
            'items.*.item_type' => 'required|string|max:50',
            'items.*.expected_quantity' => 'nullable|integer',
            'items.*.actual_quantity' => 'nullable|integer',
            'items.*.condition_found' => 'nullable|string|max:50',
            'items.*.finding_type' => 'nullable|string|max:50',
            'items.*.finding_description' => 'nullable|string',
        ]);

        // Complete the audit
        $audit->update([
            'status' => 'Completed',
            'completed_date' => now(),
            'findings_summary' => collect($validated['items'])->where('finding_type', '!=', null)->pluck('finding_description')->implode('; '),
        ]);

        // Create audit items
        foreach ($validated['items'] as $itemData) {
            $discrepancy = 0;
            if (isset($itemData['expected_quantity']) && isset($itemData['actual_quantity'])) {
                $discrepancy = $itemData['actual_quantity'] - $itemData['expected_quantity'];
            }

            RoomAuditItem::create([
                'room_audit_id' => $auditId,
                'room_asset_id' => $itemData['room_asset_id'] ?? null,
                'item_type' => $itemData['item_type'],
                'expected_quantity' => $itemData['expected_quantity'] ?? 0,
                'actual_quantity' => $itemData['actual_quantity'] ?? 0,
                'discrepancy' => $discrepancy,
                'condition_found' => $itemData['condition_found'] ?? null,
                'finding_type' => $itemData['finding_type'] ?? null,
                'finding_description' => $itemData['finding_description'] ?? null,
            ]);
        }

        return response()->json($audit->load(['auditor', 'items']));
    }

    /**
     * Get station apparatus
     */
    public function apparatus(int $id): JsonResponse
    {
        $station = Station::findOrFail($id);

        $apparatuses = $station->apparatuses()
            ->with('currentDefects')
            ->orderBy('unit_number')
            ->get();

        return response()->json([
            'station_id' => $id,
            'apparatuses' => $apparatuses,
            'total' => $apparatuses->count(),
        ]);
    }

    /**
     * Get station projects
     */
    public function projects(int $id): JsonResponse
    {
        $station = Station::findOrFail($id);

        $capitalProjects = $station->capitalProjects()
            ->with('milestones')
            ->orderBy('target_completion_date')
            ->get();

        $under25kProjects = $station->under25kProjects()
            ->with('updates')
            ->orderBy('target_completion_date')
            ->get();

        return response()->json([
            'station_id' => $id,
            'capital_projects' => $capitalProjects,
            'under_25k_projects' => $under25kProjects,
        ]);
    }
}