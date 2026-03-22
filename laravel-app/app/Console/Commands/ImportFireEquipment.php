<?php

namespace App\Console\Commands;

use App\Models\EquipmentItem;
use App\Models\InventoryLocation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportFireEquipment extends Command
{
    protected $signature = 'mbfd:import-fire-equipment 
                            {--path= : Path to CSV file}
                            {--force-overwrite-stock : Overwrite existing stock quantities}
                            {--dry-run : Preview import without saving}';

    protected $description = 'Import fire equipment inventory from CSV file';

    protected array $stats = [
        'locations_created' => 0,
        'locations_updated' => 0,
        'items_created' => 0,
        'items_updated' => 0,
        'stock_set' => 0,
        'stock_skipped' => 0,
        'rows_skipped' => 0,
        'warnings' => [],
    ];

    public function handle(): int
    {
        // Step 1: Determine CSV path
        $path = $this->option('path') ?? 'C:\Users\Peter Darley\Downloads\MBFD_supply_inventory - Sheet1.csv';
        
        if (!file_exists($path)) {
            $fallbackPath = '/mnt/data/MBFD_supply_inventory - Sheet1.csv';
            if (file_exists($fallbackPath)) {
                $path = $fallbackPath;
            } else {
                $this->error("CSV file not found at: {$path}");
                $this->error("Also tried fallback: {$fallbackPath}");
                return Command::FAILURE;
            }
        }

        $this->info("Reading CSV from: {$path}");

        // Step 2: Read CSV
        $rows = $this->readCsv($path);
        
        if (empty($rows)) {
            $this->error('No data found in CSV file');
            return Command::FAILURE;
        }

        // Step 3: Display preview
        $this->displayPreview($rows);

        // Step 4: Confirm unless dry-run
        if (!$this->option('dry-run') && !$this->confirm('Proceed with import?', true)) {
            $this->info('Import cancelled');
            return Command::SUCCESS;
        }

        // Step 5: Import data
        $this->info('Starting import...');
        
        DB::beginTransaction();
        try {
            foreach ($rows as $index => $row) {
                $this->processRow($row, $index);
            }
            
            if ($this->option('dry-run')) {
                DB::rollBack();
                $this->info('[DRY RUN] No changes committed to database');
            } else {
                DB::commit();
                $this->info('Import completed successfully');
            }
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Import failed: {$e->getMessage()}");
            $this->error("Stack trace: {$e->getTraceAsString()}");
            return Command::FAILURE;
        }

        // Step 6: Display statistics
        $this->displayStats();

        return Command::SUCCESS;
    }

    protected function readCsv(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');
        
        if (!$handle) {
            throw new \Exception("Could not open CSV file: {$path}");
        }

        // Read headers
        $headers = fgetcsv($handle);
        
        if (!$headers) {
            fclose($handle);
            throw new \Exception('CSV file is empty');
        }

        // Process rows
        $lineNumber = 1;
        while (($data = fgetcsv($handle)) !== false) {
            $lineNumber++;
            
            // Skip if not enough columns
            if (count($data) < count($headers)) {
                continue;
            }

            // Create associative array
            $row = array_combine($headers, $data);
            
            // Skip if Equipment Type is empty
            if (empty(trim($row['Equipment Type'] ?? ''))) {
                continue;
            }

            $rows[] = $row;
        }

        fclose($handle);
        
        $this->info("Parsed {$lineNumber} lines, found " . count($rows) . " valid equipment rows");
        
        return $rows;
    }

    protected function displayPreview(array $rows): void
    {
        $this->info("Found " . count($rows) . " rows to import");
        
        // Show unique locations
        $uniqueLocations = array_unique(array_map(fn($r) => $r['Location'] ?? '', $rows));
        $this->info("Unique locations: " . implode(', ', array_filter($uniqueLocations)));
        
        // Show unique shelves
        $uniqueShelves = array_unique(array_map(fn($r) => $r['Shelves'] ?? '', $rows));
        $this->info("Unique shelves: " . implode(', ', array_filter($uniqueShelves)));
        
        // Show first 5 rows in table format
        $this->newLine();
        $this->info("Sample rows (first 5):");
        $preview = array_slice($rows, 0, 5);
        
        // Only show relevant columns for preview
        $previewData = array_map(function($row) {
            return [
                'Shelf' => $row['Shelves'] ?? '',
                'Row' => $row['Rows'] ?? '',
                'Equipment' => $row['Equipment Type'] ?? '',
                'Qty' => $row['Equipment Quantity'] ?? '',
                'Location' => $row['Location'] ?? '',
            ];
        }, $preview);
        
        $this->table(
            ['Shelf', 'Row', 'Equipment', 'Qty', 'Location'],
            $previewData
        );
        
        // Show sample quantities for parsing
        $this->newLine();
        $this->info("Sample quantity formats:");
        $sampleQtys = array_slice(array_unique(array_map(fn($r) => $r['Equipment Quantity'] ?? '', $rows)), 0, 10);
        foreach ($sampleQtys as $qty) {
            if ($qty) {
                list($parsed, $unit) = $this->parseQuantity($qty);
                $this->line("  '{$qty}' → {$parsed} {$unit}");
            }
        }
    }

    protected function processRow(array $row, int $index): void
    {
        // Skip if Equipment Type is empty
        if (empty(trim($row['Equipment Type'] ?? ''))) {
            $this->stats['rows_skipped']++;
            return;
        }

        // Extract and clean data
        $locationName = trim($row['Location'] ?? '');
        $shelf = trim($row['Shelves'] ?? '');
        $rowNum = (int) ($row['Rows'] ?? 0);
        $equipmentName = trim($row['Equipment Type']);
        $description = trim($row['Description'] ?? '');
        $manufacturer = trim($row['Manufacturer'] ?? '');
        $quantityRaw = trim($row['Equipment Quantity'] ?? '0');

        // Parse quantity and unit
        list($quantity, $unit) = $this->parseQuantity($quantityRaw);

        // Upsert location
        $location = $this->upsertLocation($locationName, $shelf, $rowNum);

        // Upsert equipment item
        $item = $this->upsertEquipmentItem(
            $equipmentName,
            $description,
            $manufacturer,
            $unit,
            $location->id
        );

        // Set stock
        $this->setStock($item, $quantity, $index);
    }

    protected function parseQuantity(string $raw): array
    {
        // Handle empty
        if (empty($raw)) {
            return [0, 'each'];
        }
        
        // Parse leading integer: "5 Boxes" → 5, "2 Box" → 2
        if (preg_match('/^(\d+)\s*(.*)$/i', $raw, $matches)) {
            $qty = (int) $matches[1];
            $unitText = strtolower(trim($matches[2]));
            
            // Normalize unit
            if (str_contains($unitText, 'box')) {
                $unit = 'box';
            } elseif (str_contains($unitText, 'case')) {
                $unit = 'case';
            } elseif (str_contains($unitText, 'pack')) {
                $unit = 'pack';
            } else {
                $unit = 'each';
            }
            
            return [$qty, $unit];
        }
        
        // Fallback: try to parse as integer
        return [(int) $raw, 'each'];
    }

    protected function upsertLocation(string $locationName, string $shelf, int $row): InventoryLocation
    {
        // Normalize shelf to uppercase A-F
        $shelf = $shelf ? strtoupper(substr($shelf, 0, 1)) : null;
        
        // Ensure shelf is A-F or null
        if ($shelf && !in_array($shelf, ['A', 'B', 'C', 'D', 'E', 'F'])) {
            $this->stats['warnings'][] = "Invalid shelf '{$shelf}' for location '{$locationName}'";
            $shelf = null;
        }

        // Find or create location based on combination
        $location = InventoryLocation::firstOrCreate(
            [
                'location_name' => $locationName,
                'shelf' => $shelf,
                'row' => $row > 0 ? $row : null,
            ],
            [
                'bin' => null,
                'notes' => 'Imported from CSV',
            ]
        );

        if ($location->wasRecentlyCreated) {
            $this->stats['locations_created']++;
        }

        return $location;
    }

    protected function upsertEquipmentItem(
        string $name,
        string $description,
        string $manufacturer,
        string $unit,
        int $locationId
    ): EquipmentItem {
        // Normalize name using model method
        $normalizedName = EquipmentItem::normalizeName($name);
        
        $item = EquipmentItem::firstOrNew(['normalized_name' => $normalizedName]);
        
        $wasNew = !$item->exists;
        
        // Update fields (preserve existing if not in CSV)
        $item->name = $name;
        $item->description = $description ?: $item->description;
        $item->manufacturer = $manufacturer ?: $item->manufacturer;
        $item->location_id = $locationId;
        
        // Only set unit_of_measure if empty
        if (!$item->unit_of_measure) {
            $item->unit_of_measure = $unit;
        }
        
        $item->save();

        if ($wasNew) {
            $this->stats['items_created']++;
        } else {
            $this->stats['items_updated']++;
        }

        return $item;
    }

    protected function setStock(EquipmentItem $item, int $quantity, int $rowIndex): void
    {
        $currentStock = $item->stock ?? 0;
        
        // If item is new or stock is 0, always set
        if ($item->wasRecentlyCreated || $currentStock == 0) {
            if ($quantity > 0) {
                $item->setStock($quantity, 'Initial CSV import', "CSV row " . ($rowIndex + 2));
                $this->stats['stock_set']++;
            }
            return;
        }

        // If item exists with stock and no force flag
        if (!$this->option('force-overwrite-stock')) {
            $this->stats['stock_skipped']++;
            $this->stats['warnings'][] = "Skipped stock update for '{$item->name}' (current: {$currentStock}, CSV: {$quantity})";
            return;
        }

        // Force overwrite
        if ($quantity > 0) {
            $item->setStock($quantity, 'CSV import overwrite (forced)', "CSV row " . ($rowIndex + 2));
            $this->stats['stock_set']++;
        }
    }

    protected function displayStats(): void
    {
        $this->newLine();
        $this->info('=== Import Statistics ===');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Locations Created', $this->stats['locations_created']],
                ['Locations Updated', $this->stats['locations_updated']],
                ['Items Created', $this->stats['items_created']],
                ['Items Updated', $this->stats['items_updated']],
                ['Stock Set', $this->stats['stock_set']],
                ['Stock Skipped', $this->stats['stock_skipped']],
                ['Rows Skipped', $this->stats['rows_skipped']],
            ]
        );

        if (!empty($this->stats['warnings'])) {
            $this->newLine();
            $this->warn('=== Warnings ===');
            foreach (array_slice($this->stats['warnings'], 0, 20) as $warning) {
                $this->warn("• {$warning}");
            }
            if (count($this->stats['warnings']) > 20) {
                $this->warn("... and " . (count($this->stats['warnings']) - 20) . " more warnings");
            }
        }
    }
}
