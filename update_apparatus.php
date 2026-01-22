// Add vehicle_number and location columns to apparatuses
$schema = \Illuminate\Support\Facades\Schema::class;
$db = \Illuminate\Support\Facades\DB::class;

// Add columns if not exist
if (!$schema::hasColumn('apparatuses', 'vehicle_number')) {
    $db::statement("ALTER TABLE apparatuses ADD COLUMN vehicle_number VARCHAR(50)");
    echo "Added vehicle_number column\n";
}

if (!$schema::hasColumn('apparatuses', 'location')) {
    $db::statement("ALTER TABLE apparatuses ADD COLUMN location VARCHAR(255)");
    echo "Added location column\n";
}

if (!$schema::hasColumn('apparatuses', 'assignment')) {
    $db::statement("ALTER TABLE apparatuses ADD COLUMN assignment VARCHAR(255)");
    echo "Added assignment column\n";
}

// Extract data from notes field and populate new columns
$apparatuses = $db::table('apparatuses')->get();
foreach ($apparatuses as $apparatus) {
    $notes = $apparatus->notes ?? '';
    
    // Parse "Vehicle#: XXX, Location: YYY, Assignment: ZZZ"
    $vehicleNo = null;
    $location = null;
    $assignment = null;
    
    if (preg_match('/Vehicle#:\s*([^,]+)/', $notes, $m)) {
        $vehicleNo = trim($m[1]);
    }
    if (preg_match('/Location:\s*([^,]+)/', $notes, $m)) {
        $location = trim($m[1]);
    }
    if (preg_match('/Assignment:\s*([^,]+)/', $notes, $m)) {
        $assignment = trim($m[1]);
    }
    
    // Update record
    $db::table('apparatuses')->where('id', $apparatus->id)->update([
        'vehicle_number' => $vehicleNo,
        'location' => $location,
        'assignment' => $assignment,
    ]);
}

echo "Extracted data from notes to new columns for " . count($apparatuses) . " records\n";
