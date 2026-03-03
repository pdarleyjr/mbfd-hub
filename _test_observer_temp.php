<?php
require '/var/www/html/vendor/autoload.php';
$app = require_once '/var/www/html/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$a = App\Models\Apparatus::where('designation', 'E 1')->first();
$before = $a->reported_at ? $a->reported_at->toDateTimeString() : 'null';
echo "BEFORE: $before\n";
$beforeTs = $a->reported_at ? $a->reported_at->timestamp : 0;

// Trigger the Eloquent observer path
$a->notes = 'Observer live test ' . time();
$a->save();
$a->refresh();

$after = $a->reported_at ? $a->reported_at->toDateTimeString() : 'null';
$afterTs = $a->reported_at ? $a->reported_at->timestamp : 0;
echo "AFTER:  $after\n";
echo 'CHANGED: ' . ($afterTs > $beforeTs ? 'YES - Observer stamped reported_at!' : 'NO') . "\n";
echo 'Jobs in queue: ' . Illuminate\Support\Facades\DB::table('jobs')->count() . "\n";
