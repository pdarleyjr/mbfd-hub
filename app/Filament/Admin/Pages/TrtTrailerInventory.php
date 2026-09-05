<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\TrtInventoryCatalogItem;
use App\Models\TrtInventoryEntry;
use App\Models\TrtInventorySession;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;

class TrtTrailerInventory extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'TRT Trailer Inventory';

    protected static ?string $title = 'TRT Trailer Inventory';

    protected static ?string $slug = 'trt-trailer-inventory';

    protected static ?string $navigationGroup = 'Inventory & Logistics';

    protected static ?int $navigationSort = 9;

    protected static string $view = 'filament.admin.pages.trt-trailer-inventory';

    public ?int $selectedSessionId = null;

    public ?int $detailItemId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->can('admin.equipment.view') ?? false;
    }

    public function mount(): void
    {
        $latest = TrtInventorySession::orderByDesc('session_date')->first();
        $this->selectedSessionId = $latest?->id;
    }

    public function updatedSelectedSessionId(): void
    {
        $this->detailItemId = null;
    }

    public function showItemDetail(int $catalogItemId): void
    {
        $this->detailItemId = $catalogItemId;
    }

    public function closeItemDetail(): void
    {
        $this->detailItemId = null;
    }

    protected function getViewData(): array
    {
        $sessions = TrtInventorySession::withCount('entries')
            ->orderByDesc('session_date')
            ->limit(365)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'label' => $s->session_date->format('M j, Y')." ({$s->entries_count} entries)",
            ]);

        $aggregatedItems = [];
        $detailEntries = [];
        $stats = ['total' => 0, 'checked' => 0, 'present' => 0, 'missing' => 0, 'images' => 0, 'missing_images' => 0];

        if ($this->selectedSessionId) {
            $entries = TrtInventoryEntry::where('session_id', $this->selectedSessionId)
                ->with(['catalogItem', 'user'])
                ->orderByDesc('created_at')
                ->get();

            $allCatalogItems = TrtInventoryCatalogItem::active()->ordered()->get();
            $grouped = $entries->groupBy('catalog_item_id');

            $stats['total'] = $allCatalogItems->count();

            $aggregatedItems = $allCatalogItems->map(function ($catalogItem) use ($grouped, &$stats) {
                $group = $grouped->get($catalogItem->id);

                if (! $group || $group->isEmpty()) {
                    return [
                        'catalog_item_id' => $catalogItem->id,
                        'item_name' => $catalogItem->name,
                        'category' => $catalogItem->category,
                        'expected_qty' => $catalogItem->expected_quantity,
                        'present' => null,
                        'actual_qty' => null,
                        'condition' => null,
                        'action' => null,
                        'images' => [],
                        'missing_images' => 0,
                        'last_updated' => null,
                    ];
                }

                $latest = $group->first();
                $isPresent = $group->contains('present', true);
                $imagePaths = $group->pluck('image_path')->filter()->values();
                $images = $imagePaths
                    ->filter(fn (string $path): bool => Storage::disk('public')->exists($path))
                    ->values()
                    ->all();
                $missingImages = $imagePaths->count() - count($images);

                $stats['checked']++;
                if ($isPresent) {
                    $stats['present']++;
                } else {
                    $stats['missing']++;
                }
                $stats['images'] += count($images);
                $stats['missing_images'] += $missingImages;

                return [
                    'catalog_item_id' => $catalogItem->id,
                    'item_name' => $catalogItem->name,
                    'category' => $catalogItem->category,
                    'expected_qty' => $catalogItem->expected_quantity,
                    'present' => $isPresent,
                    'actual_qty' => $latest->actual_quantity,
                    'condition' => $latest->condition,
                    'action' => $latest->action,
                    'images' => $images,
                    'missing_images' => $missingImages,
                    'last_updated' => $latest->created_at->format('M j, g:i A'),
                ];
            })->all();

            // Detail entries for the selected item
            if ($this->detailItemId) {
                $detailEntries = ($grouped->get($this->detailItemId) ?? collect())
                    ->map(function ($entry): array {
                        $imagePath = $entry->image_path;
                        $imageExists = is_string($imagePath)
                            && $imagePath !== ''
                            && Storage::disk('public')->exists($imagePath);

                        return [
                            'user' => $entry->user?->name ?? 'Anonymous',
                            'present' => $entry->present,
                            'actual_quantity' => $entry->actual_quantity,
                            'condition' => $entry->condition,
                            'action' => $entry->action,
                            'image_path' => $imageExists ? $imagePath : null,
                            'image_missing' => filled($imagePath) && ! $imageExists,
                            'created_at' => $entry->created_at->format('M j, g:i A'),
                        ];
                    })
                    ->all();
            }
        }

        return [
            'sessions' => $sessions,
            'aggregatedItems' => $aggregatedItems,
            'stats' => $stats,
            'detailEntries' => $detailEntries,
            'detailItemName' => $this->detailItemId
                ? TrtInventoryCatalogItem::find($this->detailItemId)?->name
                : null,
        ];
    }
}
