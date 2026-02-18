<?php
$a = \App\Models\Apparatus::with('station')->orderBy('station_id')->get();
foreach ($a as $x) {
    echo "ID:{$x->id}|Unit:{$x->unit_number}|Name:{$x->name}|VehicleNo:{$x->vehicle_number}|Status:{$x->status}|Station:".($x->station ? $x->station->station_number : 'NULL')."\n";
}
$cols = \Illuminate\Support\Facades\Schema::getColumnListing('apparatuses');
echo "COLS:".implode(',', $cols)."\n";
