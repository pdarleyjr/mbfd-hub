<?php

namespace App\Http\Controllers\Api;

use App\Models\EquipmentItem;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;

class InventoryChatController extends Controller
{
    public function chat(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:500',
        ]);

        // Get inventory context for AI
        $allItems = EquipmentItem::where('is_active', true)->get();
        
        $context = [
            'low_stock_items' => $allItems
                ->filter(fn($item) => $item->stock <= $item->reorder_min)
                ->take(20)
                ->map(fn($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'stock' => $item->stock,
                    'reorder_min' => $item->reorder_min,
                ])
                ->values()
                ->toArray(),
            'top_items' => $allItems
                ->sortByDesc(fn($item) => $item->stock)
                ->take(10)
                ->map(fn($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'stock' => $item->stock,
                ])
                ->values()
                ->toArray(),
        ];

        // Call Cloudflare Worker
        $response = Http::withHeaders([
            'x-api-secret' => config('cloudflare.worker_api_secret'),
        ])->post(config('cloudflare.worker_url') . '/ai/inventory-chat', [
            'message' => $request->message,
            'inventory_context' => $context,
        ]);

        if (!$response->successful()) {
            return response()->json([
                'error' => 'Failed to process request',
            ], 500);
        }

        $aiResponse = $response->json();

        // Validate proposed actions (ensure item IDs exist)
        if (!empty($aiResponse['proposed_actions'])) {
            foreach ($aiResponse['proposed_actions'] as &$action) {
                $item = EquipmentItem::find($action['equipment_item_id'] ?? null);
                if (!$item) {
                    $action['valid'] = false;
                    $action['error'] = 'Item not found';
                } else {
                    $action['valid'] = true;
                    $action['item_name'] = $item->name;
                }
            }
        }

        return response()->json($aiResponse);
    }

    public function executeAction(Request $request)
    {
        $request->validate([
            'action' => 'required|in:increase_stock,decrease_stock,set_stock,move_location',
            'equipment_item_id' => 'required|exists:equipment_items,id',
            'qty' => 'required_unless:action,move_location|integer|min:0',
            'location_id' => 'nullable|exists:inventory_locations,id',
            'reason' => 'required|string',
        ]);

        $item = EquipmentItem::findOrFail($request->equipment_item_id);

        switch ($request->action) {
            case 'increase_stock':
                $item->increaseStock($request->qty, $request->reason, 'CHAT-' . auth()->id());
                break;
            case 'decrease_stock':
                $item->decreaseStock($request->qty, $request->reason, 'CHAT-' . auth()->id());
                break;
            case 'set_stock':
                $item->setStock($request->qty, $request->reason, 'CHAT-' . auth()->id());
                break;
            case 'move_location':
                $item->update(['location_id' => $request->location_id]);
                break;
        }

        return response()->json([
            'success' => true,
            'item' => $item->fresh(['location']),
        ]);
    }
}
