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
    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.admin.pages.equipment-intake';

    // Mode A: AI Camera Scan form state
    public ?string $scan_brand = null;
    public ?string $scan_model = null;
    public ?string $scan_serial = null;
    public ?string $scan_location = null;
    public ?string $scan_notes = null;
    public bool $scan_processing = false;
    public ?string $scan_error = null;
    public ?string $scan_success = null;

    // Mode B: Bulk import state
    public array $bulk_items = [];
    public ?string $bulk_location = null;

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
    }

    /**
     * Process the AI vision scan result sent from the frontend Alpine.js component.
     */
    public function processVisionResult(string $brand, string $model, string $serial): void
    {
        $this->scan_brand = $brand;
        $this->scan_model = $model;
        $this->scan_serial = $serial;
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
            'brand' => $this->scan_brand,
            'model' => $this->scan_model,
            'serial' => $this->scan_serial,
            'location_id' => $this->scan_location,
        ]);

        if ($result['success']) {
            $this->scan_success = 'Asset saved to Snipe-IT successfully!';
            Notification::make()
                ->title('Asset Logged')
                ->body("'{$this->scan_brand} {$this->scan_model}' has been logged in Snipe-IT.")
                ->success()
                ->send();

            // Clear form for next scan — keep location for seamless loop
            $this->resetScanForm();
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
     * Reset the scan form for the next item (keeps location).
     */
    public function resetScanForm(): void
    {
        $this->scan_brand = null;
        $this->scan_model = null;
        $this->scan_serial = null;
        $this->scan_notes = null;
        $this->scan_error = null;
        $this->scan_success = null;
        $this->scan_processing = false;
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

        // Build flat array of asset payloads, expanding quantity
        $assetPayloads = [];
        foreach ($validItems as $item) {
            $qty = max(1, (int) ($item['quantity'] ?? 1));
            for ($i = 0; $i < $qty; $i++) {
                $assetPayloads[] = [
                    'brand' => $item['category'] ?? 'Consumable',
                    'model' => $item['name'],
                    'serial' => null,
                    'location_id' => $this->bulk_location,
                ];
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

    /**
     * Get locations for the dropdown (computed property).
     */
    public function getLocationsProperty(): array
    {
        return app(SnipeItService::class)->getLocations();
    }
}
