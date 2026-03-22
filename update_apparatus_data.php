<?php
/**
 * Apparatus data update script — based on Apparatus report 3-6-2026
 * Columns: vehicle_number, designation, assignment, current_location, status, notes
 * Run via: docker exec mbfd-hub-laravel.test-1 php /tmp/update_apparatus_data.php
 */

// Bootstrap Laravel
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Apparatus;
use App\Models\Station;

// Build station map: station_number -> id
$stationMap = Station::pluck('id', 'station_number')->toArray();

$apparatusData = [
    // Station 1
    ['vehicle_number' => '20503',  'designation' => 'E 1',        'assignment' => 'Station 1', 'current_location' => 'Station 1', 'status' => 'In Service',     'notes' => ''],
    ['vehicle_number' => '002-12', 'designation' => 'L 1',        'assignment' => 'Station 1', 'current_location' => 'Station 1', 'status' => 'In Service',     'notes' => ''],
    ['vehicle_number' => '16508',  'designation' => 'R 1',        'assignment' => 'Station 1', 'current_location' => 'Station 1', 'status' => 'In Service',     'notes' => ''],
    ['vehicle_number' => '19502',  'designation' => 'R 11',       'assignment' => 'Station 1', 'current_location' => 'Station 1', 'status' => 'In Service',     'notes' => ''],
    // Station 2
    ['vehicle_number' => '002-20', 'designation' => 'A 1',        'assignment' => 'Station 2', 'current_location' => 'Station 2', 'status' => 'In Service',     'notes' => ''],
    ['vehicle_number' => '18500',  'designation' => 'A 2',        'assignment' => 'Station 2', 'current_location' => 'Station 2', 'status' => 'In Service',     'notes' => ''],
    ['vehicle_number' => '24509',  'designation' => 'E 2',        'assignment' => 'Station 2', 'current_location' => 'Station 2', 'status' => 'In Service',     'notes' => ''],
    ['vehicle_number' => '16507',  'designation' => 'R 2',        'assignment' => 'Station 2', 'current_location' => 'Station 2', 'status' => 'Out of Service', 'notes' => 'Repairs to sub floor'],
    ['vehicle_number' => '19503',  'designation' => 'R 22',       'assignment' => 'Station 2', 'current_location' => 'Station 2', 'status' => 'In Service',     'notes' => ''],
    // Station 3
    ['vehicle_number' => '002-22', 'designation' => 'E 3',        'assignment' => 'Station 3', 'current_location' => 'Station 3', 'status' => 'In Service',     'notes' => ''],
    ['vehicle_number' => '17505',  'designation' => 'L 3',        'assignment' => 'Station 3', 'current_location' => 'Station 3', 'status' => 'In Service',     'notes' => ''],
    ['vehicle_number' => '17501',  'designation' => 'R 3',        'assignment' => 'Station 3', 'current_location' => 'Station 3', 'status' => 'In Service',     'notes' => ''],
    // Station 4
    ['vehicle_number' => '20504',  'designation' => 'E 4',        'assignment' => 'Station 4', 'current_location' => 'Station 4', 'status' => 'In Service',     'notes' => ''],
    ['vehicle_number' => '17503',  'designation' => 'R 44',       'assignment' => 'Station 4', 'current_location' => 'Station 4', 'status' => 'In Service',     'notes' => ''],
    ['vehicle_number' => '17502',  'designation' => 'R 4',        'assignment' => 'Station 4', 'current_location' => 'Station 4', 'status' => 'In Service',     'notes' => ''],
    // Reserves (no permanent station assignment — Station 2 / Station 1)
    ['vehicle_number' => '002-16', 'designation' => 'E 21',       'assignment' => 'Reserve',   'current_location' => 'Station 2', 'status' => 'Available',      'notes' => ''],
    ['vehicle_number' => '002-14', 'designation' => 'E 11',       'assignment' => 'Reserve',   'current_location' => 'Station 2', 'status' => 'Available',      'notes' => ''],
    ['vehicle_number' => '002-10', 'designation' => 'E 31',       'assignment' => 'Reserve',   'current_location' => 'Station 2', 'status' => 'Available',      'notes' => ''],
    ['vehicle_number' => '002-6',  'designation' => 'L 11',       'assignment' => 'Reserve',   'current_location' => 'Station 1', 'status' => 'In Service',     'notes' => 'In service as L1'],
    ['vehicle_number' => '1033',   'designation' => 'Reserve',    'assignment' => 'Reserve',   'current_location' => 'Station 2', 'status' => 'Available',      'notes' => ''],
    ['vehicle_number' => '1034',   'designation' => 'Reserve',    'assignment' => 'Reserve',   'current_location' => 'Station 2', 'status' => 'In Service',     'notes' => 'Detail Sunday'],
    ['vehicle_number' => '1035',   'designation' => 'Reserve',    'assignment' => 'Reserve',   'current_location' => 'Station 1', 'status' => 'Available',      'notes' => ''],
    ['vehicle_number' => '1036',   'designation' => 'Reserve',    'assignment' => 'Reserve',   'current_location' => 'Station 2', 'status' => 'In Service',     'notes' => 'Detail Sunday'],
    ['vehicle_number' => '14500',  'designation' => 'Reserve',    'assignment' => 'Reserve',   'current_location' => 'Station 2', 'status' => 'Available',      'notes' => ''],
    ['vehicle_number' => '14501',  'designation' => 'Reserve',    'assignment' => 'Reserve',   'current_location' => 'Station 2', 'status' => 'In Service',     'notes' => 'In Service as R2'],
    // Captain
    ['vehicle_number' => '',       'designation' => 'Captain 5',  'assignment' => 'Station 2', 'current_location' => 'Station 2', 'status' => 'In Service',     'notes' => ''],
];

$updated = 0;
$created = 0;
$skipped = 0;

foreach ($apparatusData as $data) {
    // Resolve station_id from current_location (primary) or assignment
    $stationNumber = null;
    foreach (['current_location', 'assignment'] as $field) {
        if (preg_match('/Station\s+(\d+)/i', $data[$field] ?? '', $m)) {
            $stationNumber = $m[1];
            break;
        }
    }
    $stationId = $stationNumber ? ($stationMap[$stationNumber] ?? null) : null;

    // Find by designation + vehicle_number (most unique combo)
    $apparatus = null;
    if ($data['vehicle_number']) {
        $apparatus = Apparatus::where('vehicle_number', $data['vehicle_number'])->first();
    }
    if (!$apparatus && $data['designation'] && $data['designation'] !== 'Reserve') {
        $apparatus = Apparatus::where('designation', $data['designation'])->first();
    }

    $payload = [
        'vehicle_number'   => $data['vehicle_number'] ?: null,
        'designation'      => $data['designation'],
        'assignment'       => $data['assignment'],
        'current_location' => $data['current_location'],
        'status'           => $data['status'],
        'notes'            => $data['notes'] ?: null,
        'station_id'       => $stationId,
    ];

    if ($apparatus) {
        $apparatus->updateQuietly($payload);
        echo "UPDATED: {$data['designation']} ({$data['vehicle_number']}) -> {$data['status']} @ {$data['current_location']}\n";
        $updated++;
    } else {
        Apparatus::create($payload);
        echo "CREATED: {$data['designation']} ({$data['vehicle_number']}) -> {$data['status']} @ {$data['current_location']}\n";
        $created++;
    }
}

echo "\n=== DONE: $updated updated, $created created, $skipped skipped ===\n";
