<?php

declare(strict_types=1);

// Dedicated loopback router. Never included by production routes or bootstrap.
$root = dirname(__DIR__, 3);
$directory = getenv('RESPONSIVE_TABLES_TEST_DIRECTORY');
$allowed = realpath($root.'/test-results');
if (! is_string($directory) || ! $allowed || ! str_starts_with((string) realpath($directory), $allowed.DIRECTORY_SEPARATOR.'responsive-tables-server-')) {
    throw new RuntimeException('A dedicated responsive tables test directory is required.');
}
putenv('MBFD_ALLOW_DISPOSABLE_POSTGRES=0');
require $root.'/tests/bootstrap.php';
foreach (['DB_DATABASE' => $directory.'/tables.sqlite', 'SESSION_DRIVER' => 'file', 'APP_CONFIG_CACHE' => $directory.'/config.php', 'APP_ROUTES_CACHE' => $directory.'/routes.php', 'APP_URL' => 'http://127.0.0.1:8161'] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}
$app = require $root.'/bootstrap/app.php';
$app->useStoragePath($directory.'/storage');
foreach (['framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $path) {
    if (! is_dir($directory.'/storage/'.$path)) {
        mkdir($directory.'/storage/'.$path, 0777, true);
    }
}
if (in_array('--seed', $argv ?? [], true)) {
    if (file_exists($directory.'/tables.sqlite')) {
        throw new RuntimeException('Refusing to reuse a database.');
    }
    touch($directory.'/tables.sqlite');
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $directory.'/tables.sqlite'
        || realpath((new ReflectionClass(App\Models\PersonnelTable::class))->getFileName()) !== realpath($root.'/app/Models/PersonnelTable.php')) {
        throw new RuntimeException('Only the disposable database and current checkout may be used.');
    }
    Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    Illuminate\Support\Facades\Artisan::call('filament:assets');
    $long = static fn (string $stem, int $n): string => $stem.'-'.str_repeat('segment-of-a-very-long-synthetic-identifier-', 3).$n;
    foreach (range(1, 60) as $n) {
        $id = (string) (99000 + $n);
        App\Models\Employee::create(['employee_id' => $id, 'name' => $n === 2 ? $long('Synthetic Alpha Hyphenated Surname', $n) : 'Synthetic Member '.$n,
            'rank' => ['Captain', 'Lieutenant', 'Firefighter', 'Driver Engineer'][$n % 4], 'station' => (string) (($n % 5) + 1), 'roster_status' => 'active',
            'city_email' => $n === 3 ? 'synthetic.'.str_repeat('averyveryverylongmailboxname', 4).$id.'@miamibeachfl.gov' : 'synthetic'.$id.'@miamibeachfl.gov',
            'phone' => '305-555-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT)]);
    }
    $employee = App\Models\Employee::where('employee_id', '99001')->firstOrFail();
    $actor = App\Models\User::factory()->create(['name' => 'Synthetic Tables Administrator', 'email' => 'tables-admin@example.test',
        'password' => 'synthetic-tables-password', 'account_status' => 'active', 'must_change_password' => false,
        'employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id]);
    $actor->assignRole(Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web'));
    app(App\Services\Personnel\PersonnelTableService::class)->master($actor);
    foreach (range(1, 40) as $n) {
        App\Models\UnitMasterVehicle::create(['veh_number' => (string) (1000 + $n), 'tag_number' => 'SYN'.$n, 'make' => ['Ford', 'Chevrolet', 'Pierce', 'E-One'][$n % 4],
            'model' => $n === 5 ? $long('Model', $n) : 'Model '.$n, 'year' => (string) (2010 + $n % 15), 'color' => 'White', 'dept_code' => 'FIRE',
            'section' => 'Operations', 'employee_or_vehicle_name' => $n === 4 ? $long('Assigned Unit Name', $n) : 'Unit '.$n,
            'assignment' => 'Assignment '.$n, 'location' => $n === 6 ? $long('Station Location', $n) : 'Station '.(($n % 5) + 1),
            'serial_number' => 'SERIAL'.str_repeat('0123456789', 3).$n, 'sunpass_number' => 'SP'.$n, 'als_license' => 'ALS'.$n]);
    }
    echo 'Synthetic responsive tables fixture ready.'.PHP_EOL;
    exit;
}
if (PHP_SAPI !== 'cli-server' || ($_SERVER['SERVER_NAME'] ?? '') !== '127.0.0.1') {
    throw new RuntimeException('Loopback test server only.');
}
set_time_limit(120);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file($root.'/public'.$path) && ! str_contains($path, '..')) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root.'/public/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$app->handleRequest(Illuminate\Http\Request::capture());
