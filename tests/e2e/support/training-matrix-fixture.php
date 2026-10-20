<?php

declare(strict_types=1);

// This router is never registered in the application. It runs only under the
// dedicated loopback test server with a newly created disposable database.
$root = dirname(__DIR__, 3);
$directory = getenv('TRAINING_MATRIX_TEST_DIRECTORY');
$allowed = realpath($root.'/test-results');
if (! is_string($directory) || ! $allowed || ! str_starts_with((string) realpath($directory), $allowed.DIRECTORY_SEPARATOR.'training-matrix-server-')) {
    throw new RuntimeException('A dedicated training matrix test directory is required.');
}
putenv('MBFD_ALLOW_DISPOSABLE_POSTGRES=0');
require $root.'/tests/bootstrap.php';
foreach (Composer\Autoload\ClassLoader::getRegisteredLoaders() as $loader) {
    $loader->setPsr4('App\\', [$root.'/app']);
    $map = [];
    foreach ($loader->getClassMap() as $class => $file) {
        if (str_starts_with($class, 'App\\')) {
            $map[$class] = $root.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
        }
    }
    $loader->addClassMap($map);
    $loader->setPsr4('Database\\Factories\\', [$root.'/database/factories']);
    $loader->setPsr4('Database\\Seeders\\', [$root.'/database/seeders']);
}
foreach (['DB_DATABASE' => $directory.'/matrix.sqlite', 'SESSION_DRIVER' => 'file', 'APP_CONFIG_CACHE' => $directory.'/config.php', 'APP_ROUTES_CACHE' => $directory.'/routes.php', 'APP_URL' => 'http://127.0.0.1:8038'] as $name => $value) {
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
    if (file_exists($directory.'/matrix.sqlite')) {
        throw new RuntimeException('Refusing to reuse a database.');
    }
    touch($directory.'/matrix.sqlite');
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $directory.'/matrix.sqlite') {
        throw new RuntimeException('Only this disposable SQLite database may be migrated.');
    }
    if (realpath((new ReflectionClass(App\Models\Training\TrainingSession::class))->getFileName()) !== realpath($root.'/app/Models/Training/TrainingSession.php')) {
        throw new RuntimeException('Application classes must come from this checkout.');
    }
    Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    Illuminate\Support\Facades\Artisan::call('filament:assets');
    foreach (['training_admin', 'training_viewer', 'admin'] as $role) {
        $user = App\Models\User::factory()->create(['name' => 'Synthetic '.$role, 'email' => $role.'@example.test', 'password' => 'synthetic-matrix-password', 'account_status' => 'active', 'must_change_password' => false]);
        $user->assignRole(Spatie\Permission\Models\Role::findOrCreate($role, 'web'));
    }
    $admin = App\Models\User::where('email', 'training_admin@example.test')->firstOrFail();
    $course = App\Models\Training\TrainingCourse::create(['name' => 'Synthetic matrix course', 'classifications' => ['Driver Training'], 'track_attendance_matrix' => true, 'created_by' => $admin->id]);
    foreach (['Synthetic Alpha' => 'Firefighter', 'Synthetic Bravo' => 'Lieutenant', 'Synthetic Charlie' => 'Captain'] as $name => $rank) {
        App\Models\Employee::create(['employee_id' => 'SYNTH-'.strtoupper(explode(' ', $name)[1]), 'name' => $name, 'rank' => $rank, 'roster_status' => 'active', 'city_email' => 'synthetic-matrix-'.strtolower(explode(' ', $name)[1]).'@miamibeachfl.gov']);
    }
    foreach (['training_admin' => 'CHARLIE', 'training_viewer' => 'ALPHA', 'admin' => 'BRAVO'] as $role => $identifier) {
        $employee = App\Models\Employee::where('employee_id', 'SYNTH-'.$identifier)->firstOrFail();
        App\Models\User::where('email', $role.'@example.test')->update(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id]);
    }
    foreach (['Synthetic ladders', 'Synthetic pumping'] as $index => $name) {
        $module = App\Models\Training\TrainingModule::create(['training_course_id' => $course->id, 'name' => $name, 'sort_order' => $index, 'is_required' => true, 'created_by' => $admin->id]);
        $session = App\Models\Training\TrainingSession::create(['training_course_id' => $course->id, 'training_module_id' => $module->id, 'public_id' => (string) Illuminate\Support\Str::ulid(), 'course_name' => $course->name, 'classifications' => ['Driver Training'], 'held_on' => '2026-10-20', 'starts_at' => '2026-10-20T14:00:00Z', 'location' => 'Synthetic classroom', 'status' => 'submitted', 'attendance_source' => 'digital', 'created_by' => $admin->id]);
        if ($index === 0) {
            App\Models\Training\TrainingAttendance::create(['training_session_id' => $session->id, 'employee_profile_id' => App\Models\Employee::where('employee_id', 'SYNTH-ALPHA')->firstOrFail()->id, 'snapshot' => ['rank' => 'Firefighter', 'shift' => 'A Shift'], 'signature' => ['disk' => 'local', 'path' => 'synthetic/not-read.png', 'mime' => 'image/png', 'sha256' => str_repeat('d', 64)], 'signed_at' => now(), 'client_submission_id' => (string) Illuminate\Support\Str::uuid(), 'request_hash' => str_repeat('e', 64)]);
        }
    }
    echo 'Synthetic matrix fixture ready.'.PHP_EOL;
    exit;
}
if (PHP_SAPI !== 'cli-server' || ($_SERVER['SERVER_NAME'] ?? '') !== '127.0.0.1') {
    throw new RuntimeException('Loopback test server only.');
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file($root.'/public'.$path) && ! str_contains($path, '..')) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root.'/public/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$app->handleRequest(Illuminate\Http\Request::capture());
