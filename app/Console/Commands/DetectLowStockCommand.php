<?php

namespace App\Console\Commands;

use App\Models\StationInventoryItem;
use Illuminate\Console\Command;

class DetectLowStockCommand extends Command
{
    protected $signature = 'inventory:detect-low-stock';
    protected $description = 'Detect and flag low-stock items across all stations';

    public function handle(): int
    {
        $lowStockItems = StationInventoryItem::query()
            ->with(['station', 'inventoryItem'])
            ->get()
            ->filter(function ($item) {
                if (!$item->inventoryItem) {
                    return false;
                }
                $threshold = $item->inventoryItem->low_threshold 
                    ?? ($item->par_quantity * 0.5);
                return $item->quantity < $threshold;
            });

        $this->info("Found {$lowStockItems->count()} low-stock items");
        
        $this->table(
            ['Station', 'Item', 'Current', 'PAR', 'Status'],
            $lowStockItems->map(function ($item) {
                $threshold = $item->inventoryItem->low_threshold 
                    ?? ($item->par_quantity * 0.5);
                $percentage = $item->par_quantity > 0 
                    ? round(($item->quantity / $item->par_quantity) * 100) 
                    : 0;
                
                return [
                    $item->station->name ?? 'Unknown',
                    $item->inventoryItem->name ?? 'Unknown',
                    $item->quantity,
                    $item->par_quantity,
                    "{$percentage}% (< {$threshold})",
                ];
            })
        );

        return Command::SUCCESS;
    }
}
