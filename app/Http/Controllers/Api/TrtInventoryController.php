<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TrtInventoryCatalogItem;
use App\Models\TrtInventoryEntry;
use App\Models\TrtInventorySession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TrtInventoryController extends Controller
{
    /**
     * GET /api/public/trt-inventory/catalog
     * Returns all active catalog items grouped by category.
     */
    public function catalogIndex(): JsonResponse
    {
        $items = TrtInventoryCatalogItem::active()
            ->ordered()
            ->get();

        $grouped = $items->groupBy('category')->map(function ($categoryItems, $category) {
            return [
                'category' => $category,
                'items' => $categoryItems->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'category' => $item->category,
                    'expected_quantity' => $item->expected_quantity,
                    'sort_order' => $item->sort_order,
                ])->values(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $grouped,
        ]);
    }

    /**
     * POST /api/public/trt-inventory/submit
     * Submit inventory entries (batch). Merges into today's session.
     */
    public function submit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entries' => ['required', 'array', 'min:1', 'max:200'],
            'entries.*.catalog_item_id' => ['required', 'integer', 'exists:trt_inventory_catalog_items,id'],
            'entries.*.present' => ['nullable', 'boolean'],
            'entries.*.actual_quantity' => ['nullable', 'integer', 'min:0'],
            'entries.*.condition' => ['nullable', 'string', 'in:excellent,good,poor'],
            'entries.*.action' => ['nullable', 'string', 'in:keep,replace'],
            'entries.*.image' => ['nullable', 'string', 'max:500000'],
        ]);

        $session = TrtInventorySession::findOrCreateForToday();
        $created = [];

        foreach ($validated['entries'] as $entryData) {
            $imagePath = null;

            if (! empty($entryData['image']) && str_contains($entryData['image'], 'base64')) {
                $imagePath = $this->processBase64Image(
                    $entryData['image'],
                    (int) $entryData['catalog_item_id']
                );
            }

            $entry = TrtInventoryEntry::create([
                'session_id' => $session->id,
                'user_id' => $request->user()?->id,
                'catalog_item_id' => $entryData['catalog_item_id'],
                'present' => $entryData['present'] ?? null,
                'actual_quantity' => $entryData['actual_quantity'] ?? null,
                'condition' => $entryData['condition'] ?? null,
                'action' => $entryData['action'] ?? null,
                'image_path' => $imagePath,
            ]);

            $created[] = $entry->id;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'session_id' => $session->id,
                'session_date' => $session->session_date->toDateString(),
                'entries_created' => count($created),
            ],
        ], 201);
    }

    /**
     * GET /api/admin/trt-inventory/sessions
     * List all sessions (date desc) with entry counts.
     */
    public function sessions(): JsonResponse
    {
        $sessions = TrtInventorySession::withCount('entries')
            ->orderByDesc('session_date')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'session_date' => $s->session_date->toDateString(),
                'entries_count' => $s->entries_count,
                'created_at' => $s->created_at->toDateTimeString(),
            ]);

        return response()->json([
            'success' => true,
            'data' => $sessions,
        ]);
    }

    /**
     * GET /api/admin/trt-inventory/sessions/{id}
     * Returns aggregated item-based data for one session.
     */
    public function sessionDetail(int $id): JsonResponse
    {
        $session = TrtInventorySession::findOrFail($id);

        $entries = TrtInventoryEntry::where('session_id', $session->id)
            ->with(['catalogItem', 'user'])
            ->orderByDesc('created_at')
            ->get();

        $allCatalogItems = TrtInventoryCatalogItem::active()->ordered()->get();

        $grouped = $entries->groupBy('catalog_item_id');

        $aggregated = $allCatalogItems->map(function ($catalogItem) use ($grouped) {
            $group = $grouped->get($catalogItem->id);

            if (! $group || $group->isEmpty()) {
                return [
                    'catalog_item_id' => $catalogItem->id,
                    'item_name' => $catalogItem->name,
                    'category' => $catalogItem->category,
                    'expected_quantity' => $catalogItem->expected_quantity,
                    'present' => null,
                    'actual_quantity' => null,
                    'condition' => null,
                    'action' => null,
                    'images' => [],
                    'last_updated' => null,
                    'entries' => [],
                ];
            }

            $latest = $group->first();

            return [
                'catalog_item_id' => $catalogItem->id,
                'item_name' => $catalogItem->name,
                'category' => $catalogItem->category,
                'expected_quantity' => $catalogItem->expected_quantity,
                'present' => $group->contains('present', true),
                'actual_quantity' => $latest->actual_quantity,
                'condition' => $latest->condition,
                'action' => $latest->action,
                'images' => $group->pluck('image_path')->filter()->values()->all(),
                'last_updated' => $latest->created_at->toDateTimeString(),
                'entries' => $group->map(fn ($e) => [
                    'id' => $e->id,
                    'user' => $e->user?->name ?? 'Anonymous',
                    'present' => $e->present,
                    'actual_quantity' => $e->actual_quantity,
                    'condition' => $e->condition,
                    'action' => $e->action,
                    'image_path' => $e->image_path,
                    'created_at' => $e->created_at->toDateTimeString(),
                ])->values()->all(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'session' => [
                    'id' => $session->id,
                    'session_date' => $session->session_date->toDateString(),
                ],
                'items' => $aggregated->values(),
            ],
        ]);
    }

    /**
     * Decode base64 image, validate magic bytes, store to public disk, return path.
     */
    private function processBase64Image(string $base64, int $catalogItemId): ?string
    {
        if (! preg_match('/^data:image\/(jpeg|jpg|png|webp|gif);base64,/', $base64)) {
            return null;
        }

        $imageData = preg_replace('/^data:image\/\w+;base64,/', '', $base64);
        $decoded = base64_decode($imageData, true);

        if ($decoded === false || strlen($decoded) < 12) {
            return null;
        }

        // Validate magic bytes to prevent arbitrary file writes
        $isJpeg = str_starts_with($decoded, "\xFF\xD8\xFF");
        $isPng = str_starts_with($decoded, "\x89PNG");
        $isWebP = substr($decoded, 0, 4) === 'RIFF' && substr($decoded, 8, 4) === 'WEBP';
        $isGif = str_starts_with($decoded, 'GIF8');

        if (! $isJpeg && ! $isPng && ! $isWebP && ! $isGif) {
            return null;
        }

        $timestamp = now()->format('Ymd_His');
        $filename = "trt_{$catalogItemId}_{$timestamp}_" . Str::random(4) . '.jpg';
        $path = "trt-inventory/images/{$filename}";

        Storage::disk('public')->put($path, $decoded);

        return $path;
    }
}
