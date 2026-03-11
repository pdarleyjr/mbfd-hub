<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopWork;
use App\Models\Station;
use App\Models\Room;
use App\Models\RoomAsset;
use App\Models\RoomAudit;
use App\Models\RoomAuditItem;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;

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
        $roomTypeColumn = Schema::hasColumn('rooms', 'room_type') ? 'room_type' : 'type';
        $roomsHaveIsActive = Schema::hasColumn('rooms', 'is_active');
        $personnelTableExists = Schema::hasTable('personnel');

        $station = Station::with([
            'apparatuses' => function ($query) {
                $query->with('currentDefects')->orderBy('vehicle_number');
            },
            'capitalProjects' => function ($query) {
                $query->active()->orderBy('target_completion_date');
            },
            'under25kProjects' => function ($query) {
                $query->active()->orderBy('target_completion_date');
            },
            'rooms' => function ($query) use ($roomsHaveIsActive) {
                if ($roomsHaveIsActive) {
                    $query->where('is_active', true);
                }

                $query->withCount(['assets', 'audits'])
                    ->orderBy('floor')
                    ->orderBy('name');
            },
        ])
        ->withCount(['apparatuses', 'rooms', 'capitalProjects', 'under25kProjects'])
        ->findOrFail($id);

        $dormBedsCount = $personnelTableExists
            ? $station->personnel()->where('assignment', 'Dorm')->where('status', 'Active')->count()
            : 0;

        $personnelCount = $personnelTableExists
            ? $station->personnel()->count()
            : 0;

        $roomsByType = $station->rooms()
            ->select($roomTypeColumn)
            ->selectRaw('COUNT(*) as count')
            ->groupBy($roomTypeColumn)
            ->pluck('count', $roomTypeColumn)
            ->toArray();

        $apparatusByType = $station->apparatuses()
            ->select('type')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        $projectTotals = [
            'capital_projects' => [
                'active' => $station->capitalProjects()->count(),
                'budget' => (float) $station->capitalProjects()->sum('budget_amount'),
            ],
            'under_25k_projects' => [
                'active' => $station->under25kProjects()->count(),
                'budget' => (float) $station->under25kProjects()->sum('budget_amount'),
            ],
        ];

        $shopWorks = collect();

        if (Schema::hasColumn('shop_works', 'station_id')) {
            $shopWorks = ShopWork::query()
                ->where('station_id', $station->id)
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();
        } elseif (Schema::hasColumn('shop_works', 'apparatus_id')) {
            $apparatusIds = $station->apparatuses->pluck('id')->filter();

            if ($apparatusIds->isNotEmpty()) {
                $shopWorks = ShopWork::query()
                    ->whereIn('apparatus_id', $apparatusIds)
                    ->orderBy('created_at', 'desc')
                    ->limit(10)
                    ->get();
            }
        }

        $normalizeProjectStatus = function ($status): string {
            return match (strtolower(str_replace([' ', '-'], '_', (string) $status))) {
                'in_progress' => 'in_progress',
                'on_hold', 'waiting_for_parts' => 'on_hold',
                'completed' => 'completed',
                'cancelled' => 'cancelled',
                default => 'planning',
            };
        };

        return response()->json([
            'id' => $station->id,
            'name' => $station->getRawOriginal('name') ?: $station->name,
            'address' => $station->address ?? '',
            'city' => $station->city ?? '',
            'state' => $station->state ?? '',
            'zip_code' => $station->zip_code ?? '',
            'phone' => $station->phone ?? '',
            'fax' => $station->fax ?? null,
            'station_number' => $station->station_number,
            'latitude' => $station->latitude,
            'longitude' => $station->longitude,
            'is_active' => (bool) $station->is_active,
            'notes' => $station->notes,
            'created_at' => $station->created_at,
            'updated_at' => $station->updated_at,
            'apparatuses_count' => (int) ($station->apparatuses_count ?? $station->apparatuses->count()),
            'active_apparatuses_count' => (int) $station->apparatuses->where('status', 'Active')->count(),
            'rooms_count' => (int) ($station->rooms_count ?? $station->rooms->count()),
            'capital_projects_count' => (int) ($station->capital_projects_count ?? $station->capitalProjects->count()),
            'under_25k_projects_count' => (int) ($station->under_25k_projects_count ?? $station->under25kProjects->count()),
            'shop_works_count' => (int) $shopWorks->count(),
            'personnel_count' => (int) $personnelCount,
            'dorm_beds_count' => (int) $dormBedsCount,
            'apparatuses' => $station->apparatuses->map(function ($apparatus) {
                return [
                    'id' => $apparatus->id,
                    'name' => $apparatus->designation ?: $apparatus->name ?: $apparatus->unit_id,
                    'unit_id' => $apparatus->unit_id,
                    'type' => strtolower((string) $apparatus->type),
                    'vehicle_number' => $apparatus->vehicle_number,
                    'designation' => $apparatus->designation,
                    'slug' => $apparatus->slug,
                    'current_defects_count' => $apparatus->relationLoaded('currentDefects') ? $apparatus->currentDefects->count() : 0,
                ];
            })->values()->all(),
            'rooms' => $station->rooms->map(function ($room) use ($roomsHaveIsActive) {
                return [
                    'id' => $room->id,
                    'station_id' => $room->station_id,
                    'name' => $room->name,
                    'room_number' => null,
                    'floor' => $room->floor,
                    'type' => $room->type ?? $room->room_type ?? 'other',
                    'capacity' => $room->capacity,
                    'is_active' => $roomsHaveIsActive ? (bool) $room->is_active : true,
                    'notes' => $room->notes,
                    'assets_count' => (int) ($room->assets_count ?? 0),
                    'audits_count' => (int) ($room->audits_count ?? 0),
                    'created_at' => $room->created_at,
                    'updated_at' => $room->updated_at,
                ];
            })->values()->all(),
            'capital_projects' => $station->capitalProjects->map(function ($project) use ($normalizeProjectStatus) {
                return [
                    'id' => $project->id,
                    'project_number' => $project->project_number,
                    'title' => $project->name ?? $project->project_name ?? ('Project ' . $project->id),
                    'description' => $project->description,
                    'station_id' => $project->station_id,
                    'budget' => (float) ($project->budget_amount ?? 0),
                    'spent' => (float) ($project->spend_amount ?? 0),
                    'status' => $normalizeProjectStatus($project->status),
                    'priority' => strtolower((string) $project->priority),
                    'start_date' => $project->start_date,
                    'estimated_completion' => $project->target_completion_date,
                    'actual_completion' => $project->actual_completion_date,
                    'created_at' => $project->created_at,
                    'updated_at' => $project->updated_at,
                ];
            })->values()->all(),
            'under_25k_projects' => $station->under25kProjects->map(function ($project) use ($normalizeProjectStatus) {
                return [
                    'id' => $project->id,
                    'project_number' => $project->project_number,
                    'title' => $project->name ?? ('Project ' . $project->id),
                    'description' => $project->description,
                    'station_id' => $project->station_id,
                    'budget' => (float) ($project->budget_amount ?? 0),
                    'spent' => (float) ($project->spend_amount ?? 0),
                    'status' => $normalizeProjectStatus($project->status),
                    'priority' => strtolower((string) $project->priority),
                    'start_date' => $project->start_date,
                    'estimated_completion' => $project->target_completion_date,
                    'actual_completion' => $project->actual_completion_date,
                    'created_at' => $project->created_at,
                    'updated_at' => $project->updated_at,
                ];
            })->values()->all(),
            'shop_works' => $shopWorks->map(function ($work) use ($normalizeProjectStatus) {
                return [
                    'id' => $work->id,
                    'work_order_number' => 'SW-' . $work->id,
                    'title' => $work->project_name ?? ('Shop Work ' . $work->id),
                    'description' => $work->description,
                    'apparatus_id' => $work->apparatus_id,
                    'priority' => 'medium',
                    'status' => $normalizeProjectStatus($work->status),
                    'work_type' => $work->category ?? null,
                    'assigned_to' => $work->assigned_to,
                    'total_cost' => (float) ($work->actual_cost ?? $work->estimated_cost ?? 0),
                    'is_warranty_work' => false,
                    'is_insurance_claim' => false,
                    'created_at' => $work->created_at,
                    'updated_at' => $work->updated_at,
                ];
            })->values()->all(),
            'summary' => [
                'dorm_beds_count' => (int) $dormBedsCount,
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