$db = \Illuminate\Support\Facades\DB::class;
$rows = $db::table('apparatuses')->select('unit_id','vehicle_number','location','assignment','mileage')->limit(5)->get();
foreach($rows as $r) {
    echo "{$r->unit_id} | {$r->vehicle_number} | {$r->location} | {$r->assignment} | {$r->mileage}\n";
}
