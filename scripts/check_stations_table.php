<?php

require_once __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

echo "Checking Stations Table Structure\n";
echo "==================================\n\n";

$columns = Schema::getColumnListing('stations');
echo "Columns in stations table:\n";
foreach ($columns as $column) {
    echo "  - $column\n";
}

echo "\nSample data from stations table:\n";
$stations = DB::table('stations')->limit(3)->get();
foreach ($stations as $station) {
    print_r($station);
}
