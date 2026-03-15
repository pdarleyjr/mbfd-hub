<?php

namespace App\Http\Controllers\Api\Public\ApparatusLayout;

use App\Domain\ApparatusLayout\ApparatusCompartment;
use App\Domain\ApparatusLayout\ApparatusLayoutTool;
use App\Domain\ApparatusLayout\ApparatusLayoutSnapshot;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ApparatusLayoutController extends Controller
{
    /**
     * Get all available tools for the layout planner.
     */
    public function getTools(Request $request): JsonResponse
    {
        $tools = ApparatusLayoutTool::active()
            ->ordered()
            ->get()
            ->groupBy('category')
            ->map(function ($categoryTools) {
                return $categoryTools->map(function ($tool) {
                    return [
                        'id' => $tool->id,
                        'name' => $tool->name,
                        'category' => $tool->category,
                        'dimensions' => $tool->getDimensions(),
                        'weight' => $tool->weight,
                        'canRotate' => $tool->can_rotate,
                        'requiresClearance' => $tool->requires_clearance,
                        'clearanceDepth' => $tool->clearance_depth,
                        'iconUrl' => $tool->getIconUrl(),
                        'color' => $tool->color,
                    ];
                });
            });

        return response()->json([
            'success' => true,
            'data' => $tools,
        ]);
    }

    /**
     * Get compartments for a specific apparatus.
     */
    public function getCompartments(Request $request, int $apparatusId): JsonResponse
    {
        $compartments = ApparatusCompartment::where('apparatus_id', $apparatusId)
            ->orderBy('side')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('side')
            ->map(function ($sideCompartments) {
                return $sideCompartments->map(function ($compartment) {
                    return [
                        'id' => $compartment->id,
                        'label' => $compartment->label,
                        'side' => $compartment->side,
                        'dimensions' => $compartment->getDimensions(),
                        'shelfType' => $compartment->shelf_type,
                        'shelfCount' => $compartment->shelf_count,
                        'hasPegboard' => $compartment->has_pegboard,
                        'pegboardFaces' => $compartment->pegboard_faces,
                        'color' => $compartment->getShelfTypeColor(),
                    ];
                });
            });

        return response()->json([
            'success' => true,
            'data' => $compartments,
        ]);
    }

    /**
     * Get snapshots for a specific apparatus.
     */
    public function getSnapshots(Request $request, int $apparatusId): JsonResponse
    {
        $user = $request->user();
        
        $query = ApparatusLayoutSnapshot::where('apparatus_id', $apparatusId);
        
        // If user is authenticated, show their snapshots + published ones
        if ($user) {
            $query->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhere('is_published', true);
            });
        } else {
            // Public users only see published snapshots
            $query->where('is_published', true);
        }

        $snapshots = $query->orderBy('updated_at', 'desc')
            ->get()
            ->map(function ($snapshot) {
                return [
                    'id' => $snapshot->id,
                    'name' => $snapshot->name,
                    'isAutoSave' => $snapshot->is_auto_save,
                    'isPublished' => $snapshot->is_published,
                    'notes' => $snapshot->notes,
                    'updatedAt' => $snapshot->updated_at->toIso8601String(),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $snapshots,
        ]);
    }

    /**
     * Get a specific snapshot.
     */
    public function getSnapshot(Request $request, string $id): JsonResponse
    {
        $snapshot = ApparatusLayoutSnapshot::findOrFail($id);
        
        // Check access
        $user = $request->user();
        if (!$snapshot->is_published && (!$user || $snapshot->user_id !== $user->id)) {
            return response()->json([
                'success' => false,
                'message' => 'Snapshot not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $snapshot->id,
                'apparatusId' => $snapshot->apparatus_id,
                'name' => $snapshot->name,
                'placements' => $snapshot->placements,
                'isAutoSave' => $snapshot->is_auto_save,
                'isPublished' => $snapshot->is_published,
                'notes' => $snapshot->notes,
                'updatedAt' => $snapshot->updated_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Save a new snapshot.
     */
    public function saveSnapshot(Request $request): JsonResponse
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'apparatus_id' => 'required|integer|exists:apparatuses,id',
            'name' => 'required|string|max:255',
            'placements' => 'required|array',
            'is_auto_save' => 'boolean',
            'is_published' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $snapshot = ApparatusLayoutSnapshot::create([
            'apparatus_id' => $request->apparatus_id,
            'user_id' => $user->id,
            'name' => $request->name,
            'placements' => $request->placements,
            'is_auto_save' => $request->is_auto_save ?? false,
            'is_published' => $request->is_published ?? false,
            'notes' => $request->notes,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $snapshot->id,
                'name' => $snapshot->name,
                'updatedAt' => $snapshot->updated_at->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Update an existing snapshot.
     */
    public function updateSnapshot(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required',
            ], 401);
        }

        $snapshot = ApparatusLayoutSnapshot::findOrFail($id);

        // Check ownership
        if ($snapshot->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'placements' => 'sometimes|array',
            'is_auto_save' => 'sometimes|boolean',
            'is_published' => 'sometimes|boolean',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $snapshot->update($request->only([
            'name', 'placements', 'is_auto_save', 'is_published', 'notes'
        ]));

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $snapshot->id,
                'name' => $snapshot->name,
                'updatedAt' => $snapshot->updated_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Delete a snapshot.
     */
    public function deleteSnapshot(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required',
            ], 401);
        }

        $snapshot = ApparatusLayoutSnapshot::findOrFail($id);

        // Check ownership
        if ($snapshot->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $snapshot->delete();

        return response()->json([
            'success' => true,
            'message' => 'Snapshot deleted',
        ]);
    }

    /**
     * Get auto-save snapshot for current user and apparatus.
     */
    public function getAutoSave(Request $request, int $apparatusId): JsonResponse
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required',
            ], 401);
        }

        $snapshot = ApparatusLayoutSnapshot::where('apparatus_id', $apparatusId)
            ->where('user_id', $user->id)
            ->where('is_auto_save', true)
            ->first();

        if (!$snapshot) {
            return response()->json([
                'success' => true,
                'data' => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $snapshot->id,
                'apparatusId' => $snapshot->apparatus_id,
                'placements' => $snapshot->placements,
                'updatedAt' => $snapshot->updated_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Save auto-save snapshot.
     */
    public function saveAutoSave(Request $request): JsonResponse
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'apparatus_id' => 'required|integer|exists:apparatuses,id',
            'placements' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Find or create auto-save
        $snapshot = ApparatusLayoutSnapshot::updateOrCreate(
            [
                'apparatus_id' => $request->apparatus_id,
                'user_id' => $user->id,
                'is_auto_save' => true,
            ],
            [
                'name' => 'Auto-save',
                'placements' => $request->placements,
            ]
        );

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $snapshot->id,
                'updatedAt' => $snapshot->updated_at->toIso8601String(),
            ],
        ]);
    }
}