<?php

declare(strict_types=1);

// Isolated loopback browser fixture. Every created identity and PDF is fabricated.
$fixtureRoot = dirname(__DIR__, 3);
$root = getenv('CREW_PLANNER_SOURCE_ROOT') ?: $fixtureRoot;
$dependencies = getenv('CREW_PLANNER_DEPENDENCY_ROOT') ?: $root;
$assets = is_file($root.'/public/build/manifest.json') ? $root.'/public' : $dependencies.'/public';
$directory = getenv('CREW_PLANNER_BROWSER_DIRECTORY');
$allowed = realpath($fixtureRoot.'/test-results');
if (! is_string($directory) || ! $allowed || ! str_starts_with((string) realpath($directory), $allowed.DIRECTORY_SEPARATOR.'training-crew-planner-server-')) {
    throw new RuntimeException('A newly created disposable browser directory is required.');
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
copy($root.'/bootstrap/providers.php', $directory.'/bootstrap/providers.php');
copy($assets.'/build/manifest.json', $directory.'/public/build/manifest.json');
$app = require $root.'/bootstrap/app.php';
$app->useStoragePath($directory.'/storage');
$app->useBootstrapPath($directory.'/bootstrap');
// Publish vendor assets only into this disposable directory. Vite output remains read-only.
$app->usePublicPath($directory.'/public');
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
    foreach (['training_admin', 'training_viewer'] as $role) {
        $user = App\Models\User::factory()->create(['name' => 'Synthetic '.$role, 'email' => $role.'@example.test', 'password' => 'synthetic-crew-password', 'account_status' => 'active', 'must_change_password' => false]);
        $user->assignRole(Spatie\Permission\Models\Role::findOrCreate($role, 'web'));
    }
    $admin = App\Models\User::where('email', 'training_admin@example.test')->firstOrFail();
    $employees = [];
    foreach (['90001' => 'Captain', '90002' => 'Firefighter', '90003' => 'Lieutenant'] as $number => $rank) {
        $employees[] = App\Models\Employee::create(['employee_id' => (string) $number, 'name' => 'Synthetic '.$number, 'rank' => $rank, 'roster_status' => 'active', 'city_email' => 'synthetic-'.$number.'@miamibeachfl.gov']);
    }
    foreach (['training_admin' => 0, 'training_viewer' => 1] as $role => $index) {
        App\Models\User::where('email', $role.'@example.test')->update(['employee_profile_id' => $employees[$index]->id, 'employee_id' => $employees[$index]->employee_id]);
    }
    $fresh = App\Models\Training\TrainingCourse::create(['name' => 'Synthetic new course', 'classifications' => ['STC'], 'track_attendance_matrix' => false, 'created_by' => $admin->id]);
    $modular = App\Models\Training\TrainingCourse::create(['name' => 'Synthetic modular course', 'classifications' => ['STC'], 'track_attendance_matrix' => false, 'created_by' => $admin->id]);
    foreach (['Synthetic Module 1', 'Synthetic Module 2'] as $index => $label) {
        $module = App\Models\Training\TrainingModule::create(['training_course_id' => $modular->id, 'name' => $label, 'sort_order' => $index, 'is_required' => true, 'created_by' => $admin->id]);
        if ($index === 0) {
            $session = App\Models\Training\TrainingSession::create(['training_course_id' => $modular->id, 'training_module_id' => $module->id, 'public_id' => (string) Illuminate\Support\Str::ulid(), 'course_name' => $modular->name, 'classifications' => ['STC'], 'held_on' => '2026-10-20', 'status' => 'submitted', 'attendance_source' => 'digital', 'created_by' => $admin->id]);
            App\Models\Training\TrainingAttendance::create(['training_session_id' => $session->id, 'employee_profile_id' => $employees[0]->id, 'snapshot' => ['rank' => 'Captain'], 'signature' => ['disk' => 'local', 'path' => 'synthetic/not-read.png', 'mime' => 'image/png', 'sha256' => str_repeat('d', 64)], 'signed_at' => '2026-10-20T15:00:00Z', 'client_submission_id' => (string) Illuminate\Support\Str::uuid(), 'request_hash' => str_repeat('e', 64)]);
        }
    }
    file_put_contents($directory.'/synthetic-daily.pdf', syntheticDailyPdf());
    echo 'SYNTHETIC_CREW_FIXTURE_READY '.$directory.PHP_EOL;
    exit;
}
if (PHP_SAPI !== 'cli-server' || ($_SERVER['SERVER_NAME'] ?? '') !== '127.0.0.1') {
    throw new RuntimeException('Loopback test server only.');
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/__synthetic_daily.pdf') {
    header('Content-Type: application/pdf');
    readfile($directory.'/synthetic-daily.pdf');

    return;
}
if ($path === '/__crew_domain_fingerprint') {
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $hashes = [];
    foreach (Illuminate\Support\Facades\Schema::getTableListing() as $table) {
        if (preg_match('/^(?:training_|employees$|employee_bid_assignments$)|vacation/i', $table)) {
            $hashes[$table] = hash('sha256', json_encode(Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()->all(), JSON_THROW_ON_ERROR));
        }
    }
    header('Content-Type: application/json');
    echo json_encode($hashes, JSON_THROW_ON_ERROR);

    return;
}
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
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root.'/public/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$app->handleRequest(Illuminate\Http\Request::capture());

function syntheticDailyPdf(): string
{
    $items = [[48, 700, 'Wednesday, October 21, 2026'], [48, 680, 'B Shift'], [48, 660, 'Station 9'], [48, 640, 'Engine 9']];
    foreach (['90001', '90002', '90003'] as $index => $id) {
        $y = 620 - 20 * $index;
        array_push($items, [51, $y, 'Firefighter'], [173, $y, 'SYNTHETIC, '.$id], [311, $y, $id], [351, $y, 'SP'], [494.7, $y, '08:00'], [515.9, $y, '20:00'], [548, $y, '12']);
    }
    $content = '';
    foreach ($items as [$x, $y, $text]) {
        $content .= syntheticPdfText($x, $y, $text, 0.36040324);
    }
    $content .= syntheticPdfText(0, 20, '1 of 1', 0.5).syntheticPdfText(525, 20, '10/20/2026, 7:00 AM', 0.5);
    $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [4 0 R] /Count 1 >>', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents 5 0 R >>', '<< /Length '.strlen($content).">>\nstream\n".$content.'endstream'];
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
}

function syntheticPdfText(float $x, float $y, string $text, float $scale): string
{
    $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);

    return sprintf("q %.8F 0 0 %.8F 0 0 cm BT /F1 18 Tf 1 0 0 1 %.8F %.8F Tm (%s) Tj ET Q\n", $scale, $scale, $x / $scale, $y / $scale, $text);
}
