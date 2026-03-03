<?php
// One-time data update script based on 2-27-2026 apparatus status report
// Reserve in the report = Available in DB (reserve apparatus = available for deployment)
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
    // E 21: was Available/Reserve/Fire Fleet -> Available/Reserve/Station 2
    '002-16' => ['status' => 'Available', 'assignment' => 'Reserve', 'current_location' => 'Station 2', 'station_id' => 30, 'notes' => null],
    // E 11: was Out of Service/Reserve/Station 2 -> Available/Reserve/Station 2 (fixed fuel leak)
    '002-14' => ['status' => 'Available', 'notes' => null],
    // E 31: was Available/Reserve/Station 2 -> same, no change needed
    // '002-10' => [],  // already Available
    // L 1: was Out of Service -> In Service, Fire Fleet -> Station 1
    '002-12' => ['status' => 'In Service', 'current_location' => 'Station 1', 'notes' => null],
    // L 11: In Service -> Available (Reserve status in report), update notes
    '002-6'  => ['status' => 'Available', 'notes' => 'In service as L1'],
    // R 2: In Service -> Out of Service, repairs to sub floor
    '16507'  => ['status' => 'Out of Service', 'notes' => 'Repairs to sub floor'],
    // E 1: clear test note
    '20503'  => ['notes' => null],
    // Unnamed reserves
    '1034'   => ['notes' => 'Detail Sunday'],
    '1036'   => ['notes' => 'Detail Sunday'],
    // 1033: Out of Service -> Available (report shows Reserve with no issue note)
    '1033'   => ['status' => 'Available', 'notes' => null],
    // 14501: was In Service/Station 4 -> Available/Station 2/In Service as R2
    '14501'  => ['status' => 'Available', 'assignment' => 'Reserve', 'current_location' => 'Station 2', 'station_id' => 30, 'notes' => 'In Service as R2'],
    // R 4: clear Sunday note
    '17502'  => ['notes' => null],
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
