<?php

declare(strict_types=1);

// Isolated loopback browser fixture. Every created identity and roster row is fabricated.
$fixtureRoot = dirname(__DIR__, 3);
$root = getenv('CREW_PLANNER_SOURCE_ROOT') ?: $fixtureRoot;
$dependencies = getenv('CREW_PLANNER_DEPENDENCY_ROOT') ?: $root;
$assets = is_file($root.'/public/build/manifest.json') ? $root.'/public' : $dependencies.'/public';
$directory = getenv('CREW_PLANNER_BROWSER_DIRECTORY');
$allowed = realpath($fixtureRoot.'/test-results');
if (! is_string($directory) || ! $allowed || ! str_starts_with((string) realpath($directory), $allowed.DIRECTORY_SEPARATOR.'training-crew-planner-server-')) {
    throw new RuntimeException('A newly created disposable browser directory is required.');
}
// Match the web server: static assets must not boot Laravel or write manifests.
if (PHP_SAPI === 'cli-server') {
    if (($_SERVER['SERVER_NAME'] ?? '') !== '127.0.0.1') {
        throw new RuntimeException('Loopback test server only.');
    }
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path !== '/' && ! str_contains($path, '..')) {
        foreach ([$directory.'/public', $assets] as $assetRoot) {
            if (is_file($assetRoot.$path)) {
                header('Content-Type: '.match (pathinfo($path, PATHINFO_EXTENSION)) {
                    'js' => 'application/javascript', 'css' => 'text/css', 'svg' => 'image/svg+xml', 'woff2' => 'font/woff2', default => mime_content_type($assetRoot.$path)
                });
                readfile($assetRoot.$path);

                return;
            }
        }
    }
}
require $dependencies.'/tests/bootstrap.php';
foreach (Composer\Autoload\ClassLoader::getRegisteredLoaders() as $loader) {
    foreach (['App\\' => 'app/', 'Tests\\' => 'tests/', 'Database\\Factories\\' => 'database/factories/', 'Database\\Seeders\\' => 'database/seeders/', 'Mbfd\\PolicyLibrary\\' => 'modules/policy-library/src/'] as $prefix => $path) {
        $loader->setPsr4($prefix, [$root.'/'.$path]);
        $map = [];
        foreach ($loader->getClassMap() as $class => $file) {
            if (str_starts_with($class, $prefix)) {
                $map[$class] = $root.'/'.$path.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            }
        }
        $loader->addClassMap($map);
    }
}
foreach (['DB_DATABASE' => $directory.'/planner.sqlite', 'SESSION_DRIVER' => 'file', 'CACHE_STORE' => 'file', 'APP_CONFIG_CACHE' => 'bootstrap/cache/crew-browser-config.php', 'APP_ROUTES_CACHE' => 'bootstrap/cache/crew-browser-routes.php', 'APP_URL' => 'http://127.0.0.1:8049'] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}
foreach (['storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache', 'public/build'] as $path) {
    if (! is_dir($directory.'/'.$path)) {
        mkdir($directory.'/'.$path, 0777, true);
    }
}
foreach (['packages.php', 'services.php'] as $manifest) {
    if (! is_file($directory.'/bootstrap/cache/'.$manifest)) {
        copy($dependencies.'/bootstrap/cache/'.$manifest, $directory.'/bootstrap/cache/'.$manifest);
    }
}
if (! is_file($directory.'/bootstrap/providers.php')) {
    copy($root.'/bootstrap/providers.php', $directory.'/bootstrap/providers.php');
}
if (! is_file($directory.'/public/build/manifest.json')) {
    copy($assets.'/build/manifest.json', $directory.'/public/build/manifest.json');
}
$app = require $root.'/bootstrap/app.php';
$app->useStoragePath($directory.'/storage');
$app->useBootstrapPath($directory.'/bootstrap');
// Publish vendor assets only into this disposable directory. Vite output remains read-only.
$app->usePublicPath($directory.'/public');
// The export embeds the official logo from the public path; copy only that file into the disposable directory.
$logoSource = $root.'/public/images/training/mbfd-logo.png';
if (is_file($logoSource) && ! is_file($directory.'/public/images/training/mbfd-logo.png')) {
    if (! is_dir($directory.'/public/images/training')) {
        mkdir($directory.'/public/images/training', 0777, true);
    }
    copy($logoSource, $directory.'/public/images/training/mbfd-logo.png');
}
if (in_array('--seed', $argv ?? [], true)) {
    if (file_exists($directory.'/planner.sqlite')) {
        throw new RuntimeException('Refusing to reuse a database.');
    }
    touch($directory.'/planner.sqlite');
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $directory.'/planner.sqlite') {
        throw new RuntimeException('Only this disposable database may be seeded.');
    }
    if (realpath((new ReflectionClass(App\Models\User::class))->getFileName()) !== realpath($root.'/app/Models/User.php')) {
        throw new RuntimeException('Wrong source root.');
    }
    // This newly created synthetic database has no records to recover; avoid
    // per-migration disk flushes on Windows during disposable fixture setup.
    Illuminate\Support\Facades\DB::statement('PRAGMA synchronous = OFF');
    Illuminate\Support\Facades\DB::statement('PRAGMA journal_mode = MEMORY');
    Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    Illuminate\Support\Facades\Artisan::call('filament:assets');
    foreach (['training_admin', 'training_viewer', 'super_admin'] as $role) {
        $user = App\Models\User::factory()->create(['name' => 'Synthetic '.$role, 'email' => $role.'@example.test', 'password' => 'synthetic-crew-password', 'account_status' => 'active', 'must_change_password' => false]);
        $user->assignRole(Spatie\Permission\Models\Role::findOrCreate($role, 'web'));
    }
    $admin = App\Models\User::where('email', 'training_admin@example.test')->firstOrFail();
    $employees = [];
    foreach (['90001' => 'Captain', '90002' => 'Firefighter', '90003' => 'Lieutenant', '90004' => 'Firefighter'] as $number => $rank) {
        $employees[] = App\Models\Employee::create(['employee_id' => (string) $number, 'name' => 'Synthetic '.$number, 'rank' => $rank, 'roster_status' => 'active', 'city_email' => 'synthetic-'.$number.'@miamibeachfl.gov']);
    }
    foreach (['training_admin' => 0, 'training_viewer' => 1, 'super_admin' => 2] as $role => $index) {
        App\Models\User::where('email', $role.'@example.test')->update(['employee_profile_id' => $employees[$index]->id, 'employee_id' => $employees[$index]->employee_id]);
    }
    foreach (['desktop', 'tablet', 'phone'] as $index => $project) {
        $number = (string) (90011 + $index);
        $employee = App\Models\Employee::create(['employee_id' => $number, 'name' => 'Synthetic actor '.$project, 'rank' => 'Captain', 'roster_status' => 'active', 'city_email' => 'synthetic-actor-'.$project.'@miamibeachfl.gov']);
        $actor = App\Models\User::factory()->create(['name' => 'Synthetic training admin '.$project, 'email' => 'training_admin-'.$project.'@example.test', 'password' => 'synthetic-crew-password', 'account_status' => 'active', 'must_change_password' => false, 'employee_profile_id' => $employee->id, 'employee_id' => $number]);
        $actor->assignRole('training_admin');
        // Sending a plan is each destination's own rule, so the publisher also holds the super admin role.
        $publisherEmployee = App\Models\Employee::create(['employee_id' => (string) (90021 + $index), 'name' => 'Synthetic publisher '.$project, 'rank' => 'Captain', 'roster_status' => 'active', 'city_email' => 'synthetic-publisher-'.$project.'@miamibeachfl.gov']);
        $publisher = App\Models\User::factory()->create(['name' => 'Synthetic training publisher '.$project, 'email' => 'training_publisher-'.$project.'@example.test', 'password' => 'synthetic-crew-password', 'account_status' => 'active', 'must_change_password' => false, 'employee_profile_id' => $publisherEmployee->id, 'employee_id' => (string) (90021 + $index)]);
        $publisher->assignRole('training_admin');
        $publisher->assignRole(Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web'));
    }
    // Two places a session can be held: a Hub station from the list, or a typed venue.
    App\Models\Station::create(['name' => 'Synthetic Station 99', 'station_number' => 99, 'address' => '1 Synthetic Way', 'city' => 'Synthetic City', 'state' => 'FL', 'zip_code' => '00000', 'is_active' => true]);
    $fresh = App\Models\Training\TrainingCourse::create(['name' => 'Synthetic new course', 'classifications' => ['STC'], 'track_attendance_matrix' => false, 'created_by' => $admin->id]);
    $modular = App\Models\Training\TrainingCourse::create(['name' => 'Synthetic modular course', 'classifications' => ['STC'], 'track_attendance_matrix' => false, 'created_by' => $admin->id]);
    foreach (['Synthetic Module 1', 'Synthetic Module 2'] as $index => $label) {
        $module = App\Models\Training\TrainingModule::create(['training_course_id' => $modular->id, 'name' => $label, 'sort_order' => $index, 'is_required' => true, 'created_by' => $admin->id]);
        if ($index === 0) {
            $session = App\Models\Training\TrainingSession::create(['training_course_id' => $modular->id, 'training_module_id' => $module->id, 'public_id' => (string) Illuminate\Support\Str::ulid(), 'course_name' => $modular->name, 'classifications' => ['STC'], 'held_on' => '2026-10-20', 'status' => 'submitted', 'attendance_source' => 'digital', 'created_by' => $admin->id]);
            App\Models\Training\TrainingAttendance::create(['training_session_id' => $session->id, 'employee_profile_id' => $employees[0]->id, 'snapshot' => ['rank' => 'Captain'], 'signature' => ['disk' => 'local', 'path' => 'synthetic/not-read.png', 'mime' => 'image/png', 'sha256' => str_repeat('d', 64)], 'signed_at' => '2026-10-20T15:00:00Z', 'client_submission_id' => (string) Illuminate\Support\Str::uuid(), 'request_hash' => str_repeat('e', 64)]);
        }
    }
    // The TeleStaff roster the Hub already accepted for today's duty date. 90004 has a code nobody can read
    // (held for verification) and 90099 is on the roster but is no Hub employee.
    $dutyDate = app(App\Support\Captain5\DutyCalendar::class)->dutyDate()->format('Y-m-d');
    $onDuty = static fn (string $id, string $code = 'SP', string $availability = App\Models\DailyRoster\RosterAssignment::AVAILABILITY_WORKING): array => [
        'employee_id' => $id, 'name' => 'SYNTHETIC, '.$id, 'code' => $code, 'availability' => $availability, 'from' => '08:00', 'through' => '20:00'];
    Tests\Support\DailyRosterSnapshotFactory::publish($dutyDate, ['Station 9 / Engine 9' => [
        $onDuty('90001'), $onDuty('90002'), $onDuty('90003'),
        $onDuty('90004', 'ZZZ', App\Models\DailyRoster\RosterAssignment::AVAILABILITY_UNKNOWN), $onDuty('90099'),
    ]]);
    echo 'SYNTHETIC_CREW_FIXTURE_READY '.$directory.PHP_EOL;
    exit;
}
if (PHP_SAPI !== 'cli-server' || ($_SERVER['SERVER_NAME'] ?? '') !== '127.0.0.1') {
    throw new RuntimeException('Loopback test server only.');
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/__crew_domain_fingerprint') {
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $hashes = [];
    foreach (Illuminate\Support\Facades\Schema::getTableListing() as $table) {
        // The planner's own saved plans and send records are not training, attendance or personnel data.
        if (preg_match('/^(?:training_|employees$|employee_bid_assignments$)|vacation/i', $table) && ! str_contains($table, 'crew_plan')) {
            $hashes[$table] = hash('sha256', json_encode(Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()->all(), JSON_THROW_ON_ERROR));
        }
    }
    header('Content-Type: application/json');
    echo json_encode($hashes, JSON_THROW_ON_ERROR);

    return;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root.'/public/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$app->handleRequest(Illuminate\Http\Request::capture());
