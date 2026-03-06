<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Check if passport keys exist
if (!file_exists(storage_path('oauth-private.key'))) {
    echo "Installing Passport keys...\n";
    \Artisan::call('passport:keys', ['--force' => true]);
    echo \Artisan::output();
}

// Create personal access client if needed
$clientCount = \Laravel\Passport\Client::where('personal_access_client', true)->count();
if ($clientCount === 0) {
    echo "Creating personal access client...\n";
    \Artisan::call('passport:client', ['--personal' => true, '--name' => 'MBFD Hub Personal Access']);
    echo \Artisan::output();
}

// Create API token for admin user
$user = \App\Models\User::first();
$token = $user->createToken('MBFD Hub Master Token');
echo "API_TOKEN=" . $token->accessToken . "\n";
