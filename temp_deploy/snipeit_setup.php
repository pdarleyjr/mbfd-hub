<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Create settings record
$s = new \App\Models\Setting();
$s->id = 1;
$s->site_name = 'MBFD Inventory';
$s->default_currency = 'USD';
$s->auto_increment_assets = 1;
$s->auto_increment_prefix = 'MBFD-';
$s->save();
echo "Settings created\n";

// Make user superadmin
$u = \App\Models\User::first();
$u->permissions = '{"superuser":"1"}';
$u->activated = 1;
$u->save();
echo "User {$u->username} set as superadmin\n";
