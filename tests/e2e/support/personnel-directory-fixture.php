<?php

declare(strict_types=1);

// Dedicated loopback router. Never included by production routes or bootstrap.
$root = dirname(__DIR__, 3);
$directory = getenv('PERSONNEL_DIRECTORY_TEST_DIRECTORY');
$allowed = realpath($root.'/test-results');
if (! is_string($directory) || ! $allowed || ! str_starts_with((string) realpath($directory), $allowed.DIRECTORY_SEPARATOR.'personnel-directory-server-')) {
    throw new RuntimeException('A dedicated personnel test directory is required.');
}
putenv('MBFD_ALLOW_DISPOSABLE_POSTGRES=0');
require $root.'/tests/bootstrap.php';
foreach (['DB_DATABASE' => $directory.'/personnel.sqlite', 'SESSION_DRIVER' => 'file', 'APP_CONFIG_CACHE' => $directory.'/config.php', 'APP_ROUTES_CACHE' => $directory.'/routes.php', 'APP_URL' => 'http://127.0.0.1:8047'] as $name => $value) {
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
    if (file_exists($directory.'/personnel.sqlite')) {
        throw new RuntimeException('Refusing to reuse a database.');
    }
    touch($directory.'/personnel.sqlite');
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $directory.'/personnel.sqlite'
        || realpath((new ReflectionClass(App\Models\PersonnelTable::class))->getFileName()) !== realpath($root.'/app/Models/PersonnelTable.php')) {
        throw new RuntimeException('Only the disposable database and current checkout may be used.');
    }
    Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    Illuminate\Support\Facades\Artisan::call('filament:assets');
    foreach ([['99001', 'Synthetic Administrator', 'Captain'], ['99002', 'Synthetic Alpha', 'Firefighter'], ['99003', 'Synthetic Bravo', 'Lieutenant']] as [$id, $name, $rank]) {
        App\Models\Employee::create(['employee_id' => $id, 'name' => $name, 'rank' => $rank, 'station' => '1', 'roster_status' => 'active', 'city_email' => 'personnel'.$id.'@miamibeachfl.gov']);
    }
    $employee = App\Models\Employee::where('employee_id', '99001')->firstOrFail();
    $actor = App\Models\User::factory()->create(['name' => 'Synthetic Personnel Administrator', 'email' => 'personnel-admin@example.test',
        'password' => 'synthetic-personnel-password', 'account_status' => 'active', 'must_change_password' => false,
        'employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id]);
    $actor->assignRole(Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web'));
    $member = App\Models\User::factory()->create(['name' => 'Synthetic Personnel Member', 'email' => 'personnel-member@example.test',
        'password' => 'synthetic-personnel-password', 'account_status' => 'active', 'must_change_password' => false,
        'employee_profile_id' => App\Models\Employee::where('employee_id', '99002')->value('id'), 'employee_id' => '99002']);
    $service = app(App\Services\Personnel\PersonnelTableService::class);
    $service->master($actor);
    $alpha = App\Models\Employee::where('employee_id', '99002')->firstOrFail();
    foreach (['fixed', 'dynamic'] as $mode) {
        $column = (string) Illuminate\Support\Str::uuid();
        $table = $service->create($actor, ['name' => 'Synthetic '.$mode.' tracking', 'visibility' => 'shared', 'membership_mode' => $mode,
            'criteria' => ['active_only' => true, 'rank' => ['Firefighter']], 'employee_ids' => $mode === 'fixed' ? [$alpha->id] : [],
            'source_columns' => ['name', 'employee_id', 'rank'], 'columns' => [
                ['id' => $column, 'label' => 'Completed', 'type' => 'checkbox', 'options' => [], 'hidden' => false, 'color' => null],
            ]]);
        $service->setCell($actor, $table, $alpha, $column, true, 0);
    }
    echo 'Synthetic personnel fixture ready.'.PHP_EOL;
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
