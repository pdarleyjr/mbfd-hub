<?php
// One-time data update script based on 2-27-2026 apparatus status report
// Run: docker exec mbfd-hub-laravel.test-1 php /var/www/html/_update_apparatus_data.php
// Delete after running.

require '/var/www/html/vendor/autoload.php';
$app = require_once '/var/www/html/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Apparatus;
use Illuminate\Support\Facades\DB;

$updates = [
    // vehicle_number => [field => value, ...]
    '002-16' => ['status' => 'Reserve', 'assignment' => 'Reserve', 'current_location' => 'Station 2', 'station_id' => 30, 'notes' => null],
    '002-14' => ['status' => 'Reserve', 'notes' => null],
    '002-10' => ['status' => 'Reserve'],
    '002-12' => ['status' => 'In Service', 'current_location' => 'Station 1', 'notes' => null],
    '002-6'  => ['status' => 'Reserve', 'notes' => 'In service as L1'],
    '16507'  => ['status' => 'Out of Service', 'notes' => 'Repairs to sub floor'],
    '20503'  => ['notes' => null],  // clear test note on E 1
    '1034'   => ['notes' => 'Detail Sunday'],
    '1036'   => ['notes' => 'Detail Sunday'],
    '1033'   => ['status' => 'Reserve', 'notes' => null],
    '14501'  => ['status' => 'Reserve', 'assignment' => 'Reserve', 'current_location' => 'Station 2', 'station_id' => 30, 'notes' => 'In Service as R2'],
];

$count = 0;
foreach ($updates as $vehicleNumber => $fields) {
    $apparatus = Apparatus::where('vehicle_number', $vehicleNumber)->first();
    if (!$apparatus) {
        echo "WARNING: vehicle_number={$vehicleNumber} not found, skipping\n";
        continue;
    }
    $apparatus->update($fields);
    $count++;
    echo "Updated {$vehicleNumber} ({$apparatus->designation}): " . json_encode($fields) . "\n";
}

echo "\nDone. Updated {$count} apparatus records.\n";
echo 'Jobs queued: ' . DB::table('jobs')->count() . "\n";
