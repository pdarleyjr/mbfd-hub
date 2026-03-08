<?php

namespace App\Filament\Admin\Pages;

use App\Services\SnipeItService;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;

class EquipmentIntake extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-camera';
    protected static ?string $navigationLabel = 'Equipment Intake';
    protected static ?string $title = 'Equipment Intake';
    protected static ?string $slug = 'equipment-intake';
    protected static ?string $navigationGroup = 'Inventory & Logistics';
    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.admin.pages.equipment-intake';

    // Mode A: AI Camera Scan form state
    public ?string $scan_brand = null;
    public ?string $scan_model = null;
    public ?string $scan_serial = null;
    public ?string $scan_location = null;
    public ?string $scan_notes = null;
    public ?string $scan_type = 'hardware';  // hardware | accessory | consumable | component
    public ?string $scan_item_name = null;   // AI-identified descriptive name
    public ?string $scan_category = null;    // AI-identified category (Saw, Fan, Rescue Tool, etc.)
    public bool $scan_processing = false;
    public ?string $scan_error = null;
    public ?string $scan_success = null;

    // Mode B: Bulk import state
    public array $bulk_items = [];
    public ?string $bulk_location = null;

    // Mode C: AI Bulk Import state
    public array $ai_bulk_items = [];   // each row: {thumbnail, brand, model, serial, category, notes, location_id, processing, error}
    public ?string $ai_bulk_global_location = null;

    // Shared: new location creation
    public ?string $new_location_name = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        return $user->hasRole(['super_admin', 'admin', 'logistics_admin']);
    }

    public function mount(): void
    {
        $this->bulk_items = [
            ['name' => '', 'quantity' => 1, 'category' => '', 'notes' => ''],
        ];
        $this->ai_bulk_items = [];
    }

    /**
     * Process the AI vision scan result sent from the frontend Alpine.js component.
     */
    public function processVisionResult(string $brand, string $model, string $serial, string $notes = '', string $item_name = '', string $category = ''): void
    {
        $this->scan_brand = $brand;
        $this->scan_model = $model;
        $this->scan_serial = $serial;
        $this->scan_item_name = $item_name ?: null;
        $this->scan_category = $category ?: null;
        // Only set notes if AI found something useful and field is currently empty
        if ($notes && !$this->scan_notes) {
            $this->scan_notes = 'AI: ' . $notes;
        }
        $this->scan_error = null;
        $this->scan_processing = false;
    }

    /**
     * Handle vision scan error from frontend.
     */
    public function handleScanError(string $message): void
    {
        $this->scan_error = $message;
        $this->scan_processing = false;
    }

    /**
     * Approve & Save with data passed directly from Alpine.js.
     * This bypasses the $wire.set() async race condition by receiving
     * the current field values inline with the method call.
     */
    public function approveAndSaveWithData(string $brand = '', string $model = '', string $serial = '', string $item_name = '', string $category = ''): void
    {
        // Override PHP properties with the values passed from Alpine
        if ($brand)     $this->scan_brand     = $brand;
        if ($model)     $this->scan_model      = $model;
        if ($serial)    $this->scan_serial     = $serial;
        if ($item_name) $this->scan_item_name  = $item_name;
        if ($category)  $this->scan_category   = $category;

        // Now call the standard save flow
        $this->approveAndSave();
    }

    /**
     * Approve & Save: send the scanned asset to Snipe-IT.
     */
    public function approveAndSave(): void
    {
        if (empty($this->scan_location)) {
            Notification::make()
                ->title('Location Required')
                ->body('Please select a location before saving.')
                ->danger()
                ->send();
            return;
        }

        $snipeIt = app(SnipeItService::class);

        $result = $snipeIt->createAsset([
            'brand'       => $this->scan_brand,
            'model'       => $this->scan_model,
            'serial'      => $this->scan_serial,
            'location_id' => $this->scan_location,
            'notes'       => $this->scan_notes,
            'category'    => $this->scan_category ?: 'General',
            'item_name'   => $this->scan_item_name,
            'scan_type'   => $this->scan_type ?? 'hardware',
        ]);

        if ($result['success']) {
            $this->scan_success = 'Asset saved to Snipe-IT successfully!';
            Notification::make()
                ->title('Asset Logged')
                ->body("'{$this->scan_brand} {$this->scan_model}' has been logged in Snipe-IT.")
                ->success()
                ->send();

            // Clear form for next scan — keep location and item type for seamless loop
            $this->resetScanForm();

            // Notify Alpine.js to clear the camera buffer and ready the next scan
            $this->dispatch('equipment-saved');
        } else {
            $error = is_array($result['error']) ? json_encode($result['error']) : $result['error'];
            Notification::make()
                ->title('Snipe-IT Error')
                ->body($error)
                ->danger()
                ->send();
        }
    }

    /**
     * Reset the scan form for the next item (keeps location and scan_type).
     */
    public function resetScanForm(): void
    {
        $this->scan_brand = null;
        $this->scan_model = null;
        $this->scan_serial = null;
        $this->scan_notes = null;
        $this->scan_item_name = null;
        $this->scan_category = null;
        $this->scan_error = null;
        $this->scan_success = null;
        $this->scan_processing = false;
        // NOTE: scan_location and scan_type are intentionally preserved for seamless continuous scanning
    }

    /**
     * Submit bulk items to Snipe-IT.
     */
    public function submitBulkItems(): void
    {
        if (empty($this->bulk_location)) {
            Notification::make()
                ->title('Location Required')
                ->body('Please select a location for the bulk import.')
                ->danger()
                ->send();
            return;
        }

        $validItems = array_filter($this->bulk_items, fn($item) => !empty($item['name']));

        if (empty($validItems)) {
            Notification::make()
                ->title('No Items')
                ->body('Please add at least one item with a name.')
                ->warning()
                ->send();
            return;
        }

        $snipeIt = app(SnipeItService::class);
        $successCount = 0;
        $failCount = 0;

        // Build payloads. Consumables use qty; hardware items expand individually.
        $consumableCategories = ['Consumable', 'Medical', 'Medical Supply'];
        $assetPayloads = [];
        foreach ($validItems as $item) {
            $qty          = max(1, (int) ($item['quantity'] ?? 1));
            $categoryName = $item['category'] ?? 'General';
            $isConsumable = in_array($categoryName, $consumableCategories, true);

            if ($isConsumable) {
                // One consumable entry with qty
                $assetPayloads[] = [
                    'name'        => $item['name'],
                    'model'       => $item['name'],
                    'category'    => $categoryName,
                    'qty'         => $qty,
                    'notes'       => $item['notes'] ?? '',
                    'serial'      => null,
                    'location_id' => $this->bulk_location,
                ];
            } else {
                // Hardware: create one asset per unit
                for ($i = 0; $i < $qty; $i++) {
                    $assetPayloads[] = [
                        'name'        => $item['name'],
                        'model'       => $item['name'],
                        'category'    => $categoryName,
                        'qty'         => 1,
                        'notes'       => $item['notes'] ?? '',
                        'serial'      => null,
                        'location_id' => $this->bulk_location,
                    ];
                }
            }
        }

        $results = $snipeIt->bulkCreateAssets($assetPayloads);

        foreach ($results as $result) {
            if ($result['success']) {
                $successCount++;
            } else {
                $failCount++;
            }
        }

        if ($successCount > 0) {
            Notification::make()
                ->title('Bulk Import Complete')
                ->body("{$successCount} item(s) logged to Snipe-IT." . ($failCount > 0 ? " {$failCount} failed." : ''))
                ->success()
                ->send();
        }

        if ($failCount > 0 && $successCount === 0) {
            Notification::make()
                ->title('Bulk Import Failed')
                ->body("All {$failCount} item(s) failed to save.")
                ->danger()
                ->send();
        }

        // Reset bulk form
        $this->bulk_items = [
            ['name' => '', 'quantity' => 1, 'category' => '', 'notes' => ''],
        ];
    }

    /**
     * Add a row to the bulk items list.
     */
    public function addBulkRow(): void
    {
        $this->bulk_items[] = ['name' => '', 'quantity' => 1, 'category' => '', 'notes' => ''];
    }

    /**
     * Remove a row from the bulk items list.
     */
    public function removeBulkRow(int $index): void
    {
        if (count($this->bulk_items) > 1) {
            unset($this->bulk_items[$index]);
            $this->bulk_items = array_values($this->bulk_items);
        }
    }

    // =========================================================================
    // Mode C: AI Bulk Import
    // =========================================================================

    /**
     * Called from Alpine.js when the AI has finished analyzing one image in bulk mode.
     * Adds a new row to the ai_bulk_items array with the returned data.
     *
     * @param string $brand
     * @param string $model
     * @param string $serial
     * @param string $thumbnail  data-URI thumbnail of the image
     * @param int    $index      which slot to update (-1 = append new row)
     */
    public function aiBulkAddResult(string $brand, string $model, string $serial, string $thumbnail, int $index = -1): void
    {
        $row = [
            'thumbnail'   => $thumbnail,
            'brand'       => $brand !== 'Unknown' ? $brand : '',
            'model'       => $model !== 'Unknown' ? $model : '',
            'serial'      => $serial !== 'Unknown' ? $serial : '',
            'category'    => 'General',
            'notes'       => '',
            'location_id' => $this->ai_bulk_global_location ?? '',
            'error'       => null,
        ];

        if ($index >= 0 && isset($this->ai_bulk_items[$index])) {
            $this->ai_bulk_items[$index] = $row;
        } else {
            $this->ai_bulk_items[] = $row;
        }
    }

    /**
     * Called from Alpine.js when a single bulk scan fails.
     */
    public function aiBulkRowError(string $message, int $index = -1): void
    {
        $row = [
            'thumbnail'   => '',
            'brand'       => '',
            'model'       => '',
            'serial'      => '',
            'category'    => 'General',
            'notes'       => '',
            'location_id' => $this->ai_bulk_global_location ?? '',
            'error'       => $message,
        ];

        if ($index >= 0 && isset($this->ai_bulk_items[$index])) {
            $this->ai_bulk_items[$index] = array_merge($this->ai_bulk_items[$index], ['error' => $message]);
        } else {
            $this->ai_bulk_items[] = $row;
        }
    }

    /**
     * Apply the global location to all AI bulk rows.
     */
    public function applyGlobalLocationToBulk(): void
    {
        if (empty($this->ai_bulk_global_location)) return;

        $this->ai_bulk_items = array_map(function ($item) {
            $item['location_id'] = $this->ai_bulk_global_location;
            return $item;
        }, $this->ai_bulk_items);
    }

    /**
     * Remove a row from the AI bulk grid.
     */
    public function removeAiBulkRow(int $index): void
    {
        unset($this->ai_bulk_items[$index]);
        $this->ai_bulk_items = array_values($this->ai_bulk_items);
    }

    /**
     * Submit all AI bulk rows to Snipe-IT.
     */
    public function submitAiBulkItems(): void
    {
        $validItems = array_filter($this->ai_bulk_items, function ($item) {
            return !empty($item['brand']) || !empty($item['model']) || !empty($item['serial']);
        });

        if (empty($validItems)) {
            Notification::make()
                ->title('No Items')
                ->body('Please scan some equipment photos first.')
                ->warning()
                ->send();
            return;
        }

        // Validate all rows have a location
        $missingLocation = false;
        foreach ($validItems as $item) {
            if (empty($item['location_id'])) {
                $missingLocation = true;
                break;
            }
        }

        if ($missingLocation) {
            Notification::make()
                ->title('Location Required')
                ->body('Please assign a location to all items before submitting. Use "Apply to All" for bulk assignment.')
                ->danger()
                ->send();
            return;
        }

        $snipeIt = app(SnipeItService::class);

        $assetPayloads = array_map(fn($item) => [
            'brand'       => $item['brand'] ?: 'Unknown',
            'model'       => $item['model'] ?: ($item['brand'] ?: 'Unknown Equipment'),
            'serial'      => $item['serial'] ?: null,
            'category'    => $item['category'] ?: 'General',
            'notes'       => $item['notes'] ?? '',
            'location_id' => $item['location_id'],
            'qty'         => 1,
        ], array_values($validItems));

        $results = $snipeIt->bulkCreateAssets($assetPayloads);

        $successCount = 0;
        $failCount    = 0;
        foreach ($results as $result) {
            if ($result['success']) {
                $successCount++;
            } else {
                $failCount++;
                Log::warning('AI Bulk Import row failed', $result);
            }
        }

        if ($successCount > 0) {
            Notification::make()
                ->title('AI Bulk Import Complete')
                ->body("{$successCount} item(s) logged to Snipe-IT." . ($failCount > 0 ? " {$failCount} failed — check logs." : ''))
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('AI Bulk Import Failed')
                ->body("All {$failCount} item(s) failed to save.")
                ->danger()
                ->send();
        }

        if ($successCount > 0) {
            $this->ai_bulk_items = [];
        }
    }

    /**
     * Reset the AI bulk import grid.
     */
    public function resetAiBulk(): void
    {
        $this->ai_bulk_items = [];
        $this->ai_bulk_global_location = null;
    }

    // =========================================================================
    // Shared helpers
    // =========================================================================

    /**
     * Create a new location in Snipe-IT and select it.
     */
    public function createNewLocation(): void
    {
        if (empty($this->new_location_name)) {
            Notification::make()
                ->title('Location name required')
                ->body('Please enter a name for the new location.')
                ->warning()
                ->send();
            return;
        }

        $snipeIt = app(SnipeItService::class);
        $result = $snipeIt->createLocation($this->new_location_name);

        if ($result['success']) {
            $newId = $result['data']['payload']['id'] ?? $result['data']['id'] ?? null;
            Notification::make()
                ->title('Location Created')
                ->body("'{$this->new_location_name}' has been created in Snipe-IT.")
                ->success()
                ->send();

            // Auto-select the new location
            if ($newId) {
                $this->scan_location = (string) $newId;
                $this->bulk_location = (string) $newId;
                $this->ai_bulk_global_location = (string) $newId;
            }
            $this->new_location_name = null;
        } else {
            $error = is_array($result['error']) ? json_encode($result['error']) : $result['error'];
            Notification::make()
                ->title('Failed to create location')
                ->body($error)
                ->danger()
                ->send();
        }
    }

    /**
     * Get locations for the dropdown (computed property).
     */
    public function getLocationsProperty(): array
    {
        return app(SnipeItService::class)->getLocations();
    }
}
