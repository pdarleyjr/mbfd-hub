<?php

declare(strict_types=1);

// Dedicated loopback fixture. All identities, vehicles and documents are synthetic.
$root = dirname(__DIR__, 2);
$directory = getenv('VEHICLE_DOCUMENTS_BROWSER_DIRECTORY');
$assets = getenv('VEHICLE_DOCUMENTS_ASSET_ROOT') ?: $root.'/public';
$allowed = realpath($root.'/test-results');
if (! is_string($directory) || ! $allowed || ! str_starts_with((string) realpath($directory), $allowed.DIRECTORY_SEPARATOR.'vehicle-documents-server-')) {
    throw new RuntimeException('A newly created disposable vehicle-document test directory is required.');
}
if (PHP_SAPI === 'cli-server') {
    if (($_SERVER['SERVER_NAME'] ?? '') !== '127.0.0.1') {
        throw new RuntimeException('Loopback test server only.');
    }
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_string($path) && ! str_contains($path, '..') && ! str_contains($path, "\0") && ! str_contains($path, '\\')) {
        foreach ([$directory.'/public', $assets, $root.'/public'] as $assetRoot) {
            $file = realpath($assetRoot.$path);
            $prefix = realpath($assetRoot);
            if ($path !== '/' && $file !== false && $prefix !== false && str_starts_with(str_replace('\\', '/', $file), rtrim(str_replace('\\', '/', $prefix), '/').'/') && is_file($file) && strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php') {
                header('Content-Type: '.match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
                    'js', 'mjs' => 'application/javascript', 'css' => 'text/css', 'svg' => 'image/svg+xml',
                    'woff2' => 'font/woff2', 'json' => 'application/json', default => mime_content_type($file),
                });
                readfile($file);

                return;
            }
        }
    }
}
putenv('MBFD_ALLOW_DISPOSABLE_POSTGRES=0');
require $root.'/tests/bootstrap.php';
foreach ([
    'DB_DATABASE' => $directory.'/vehicles.sqlite', 'SESSION_DRIVER' => 'file', 'CACHE_STORE' => 'file',
    'APP_CONFIG_CACHE' => $directory.'/bootstrap/cache/config.php', 'APP_ROUTES_CACHE' => $directory.'/bootstrap/cache/routes.php',
    'APP_URL' => 'http://127.0.0.1:8053', 'POLICY_LIBRARY_SCANNER_ENABLED' => 'false',
] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}
foreach (['storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache', 'public/build', 'public/images'] as $path) {
    if (! is_dir($directory.'/'.$path)) {
        mkdir($directory.'/'.$path, 0700, true);
    }
}
foreach (['packages.php', 'services.php'] as $manifest) {
    if (is_file($root.'/bootstrap/cache/'.$manifest) && ! is_file($directory.'/bootstrap/cache/'.$manifest)) {
        copy($root.'/bootstrap/cache/'.$manifest, $directory.'/bootstrap/cache/'.$manifest);
    }
}
if (! is_file($directory.'/bootstrap/providers.php')) {
    copy($root.'/bootstrap/providers.php', $directory.'/bootstrap/providers.php');
}
if (! is_file($directory.'/public/build/manifest.json')) {
    copy($assets.'/build/manifest.json', $directory.'/public/build/manifest.json');
}
if (! is_file($directory.'/public/images/mbfd-logo-vehicle-reports.png')) {
    copy($root.'/public/images/mbfd-logo-vehicle-reports.png', $directory.'/public/images/mbfd-logo-vehicle-reports.png');
}
$app = require $root.'/bootstrap/app.php';
$app->useStoragePath($directory.'/storage');
$app->useBootstrapPath($directory.'/bootstrap');
$app->usePublicPath($directory.'/public');
if (in_array('--seed', $argv ?? [], true)) {
    if (file_exists($directory.'/vehicles.sqlite')) {
        throw new RuntimeException('Refusing to reuse a browser-fixture database.');
    }
    touch($directory.'/vehicles.sqlite');
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $directory.'/vehicles.sqlite'
        || realpath((new ReflectionClass(App\Models\VehicleDocument::class))->getFileName()) !== realpath($root.'/app/Models/VehicleDocument.php')) {
        throw new RuntimeException('Only this disposable SQLite database and exact candidate source may be used.');
    }
    Illuminate\Support\Facades\DB::statement('PRAGMA synchronous = OFF');
    Illuminate\Support\Facades\DB::statement('PRAGMA journal_mode = MEMORY');
    Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    Illuminate\Support\Facades\Artisan::call('filament:assets');
    $pdf = Barryvdh\DomPDF\Facade\Pdf::loadHTML('<html><body><p>Synthetic vehicle document — browser upload validation.</p></body></html>')->output();
    file_put_contents($directory.'/synthetic-upload.pdf', $pdf);
    $vehicles = [];
    foreach (['desktop', 'tablet', 'phone'] as $index => $device) {
        $employee = App\Models\Employee::create([
            'employee_id' => '9980'.$index, 'name' => 'Synthetic Fleet '.$device, 'rank' => 'Captain',
            'roster_status' => 'active', 'city_email' => 'synthetic-fleet-'.$device.'@miamibeachfl.gov',
        ]);
        $actor = App\Models\User::factory()->create([
            'name' => 'Synthetic Fleet '.$device, 'email' => 'vehicle-'.$device.'@example.test',
            'password' => 'synthetic-vehicle-password', 'account_status' => 'active', 'must_change_password' => false,
            'employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id,
        ]);
        $actor->assignRole(Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web'));
        $vehicle = App\Models\UnitMasterVehicle::create([
            'veh_number' => 'QA-'.strtoupper($device), 'make' => 'Ford', 'model' => 'F-150', 'year' => '2022',
            'serial_number' => '000123'.($index + 1), 'tag_number' => '0012'.$index, 'dept_code' => 'FIRE',
            'color' => 'White', 'location' => 'Synthetic station',
        ]);
        $original = $directory.'/seed-'.$device.'.pdf';
        file_put_contents($original, Barryvdh\DomPDF\Facade\Pdf::loadHTML('<p>Synthetic original record for '.e($device).'</p>')->output());
        app(App\Services\Fleet\VehicleDocumentLibrary::class)->ingest($original, [
            'original_filename' => 'Seed original.pdf', 'title' => 'Synthetic original record', 'category' => 'accidents',
            'document_date' => '2025-06-14', 'is_sensitive' => true, 'review_status' => 'verified',
            'source_type' => 'manual_upload',
        ], [$vehicle->id], $actor);
        $vehicles[$device] = $vehicle->id;
    }
    file_put_contents($directory.'/fixture.json', json_encode(['vehicles' => $vehicles, 'synthetic_pdf' => $directory.'/synthetic-upload.pdf'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo 'SYNTHETIC_VEHICLE_DOCUMENTS_READY '.$directory.PHP_EOL;
    exit;
}
if (PHP_SAPI !== 'cli-server' || ($_SERVER['SERVER_NAME'] ?? '') !== '127.0.0.1') {
    throw new RuntimeException('Loopback test server only.');
}
// Livewire selects this disk in APP_ENV=testing; keep it stable across real HTTP requests.
$app->booted(static function () use ($directory): void {
    config(['filesystems.disks.tmp-for-tests' => [
        'driver' => 'local', 'root' => $directory.'/storage/app/livewire-testing', 'visibility' => 'private', 'throw' => true,
    ]]);
});
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root.'/public/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$app->handleRequest(Illuminate\Http\Request::capture());
