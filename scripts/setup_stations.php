<?php

use Illuminate\Support\Facades\Hash;
use App\Models\Station;
use App\Models\InventoryItem;
use App\Models\StationInventoryItem;

// Update PINs for all stations
$pins = ['1' => '1051', '2' => '2300', '3' => '5403', '4' => '6880', '6' => '1234'];
foreach ($pins as $num => $pin) {
    $updated = Station::where('station_number', $num)->update(['inventory_pin_hash' => Hash::make($pin)]);
    echo "Station $num PIN set to $pin (updated: $updated)\n";
}

// Seed inventory items for station 6
$station6 = Station::where('station_number', '6')->first();
if ($station6) {
    $items = InventoryItem::all();
    $count = 0;
    foreach ($items as $item) {
        StationInventoryItem::firstOrCreate(
            ['station_id' => $station6->id, 'inventory_item_id' => $item->id],
            ['on_hand' => $item->par_quantity, 'status' => 'ok', 'last_updated_at' => null]
        );
        $count++;
    }
    echo "Seeded $count inventory items for Station 6 (id: {$station6->id})\n";
} else {
    echo "Station 6 not found!\n";
}

// Verify
echo "\nAll stations:\n";
$stations = Station::orderBy('station_number')->get(['id', 'station_number', 'address']);
foreach ($stations as $s) {
    echo "  ID={$s->id} Station {$s->station_number} - {$s->address}\n";
}
