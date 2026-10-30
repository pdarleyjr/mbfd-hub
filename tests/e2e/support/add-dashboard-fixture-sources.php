<?php

declare(strict_types=1);

/*
 * Test-only: adds the synthetic dashboard fixture sources (tests/Support/Dashboards) to a disposable
 * Laravel config cache written by `artisan config:cache` for the admin dashboards browser suite.
 * Usage: php add-dashboard-fixture-sources.php <bootstrap/cache/admin-dashboards-config.php>
 */

$path = $argv[1] ?? '';
if (! is_string($path) || ! str_ends_with($path, 'admin-dashboards-config.php') || ! is_file($path)) {
    fwrite(STDERR, "Expected the admin-dashboards config cache path.\n");
    exit(2);
}

$config = require $path;
$config['dashboards']['vehicle_sources'] = ['Tests\\Support\\Dashboards\\FixtureVehicleSource'];
$config['dashboards']['station_sources'] = ['Tests\\Support\\Dashboards\\FixtureStationSource', 'Tests\\Support\\Dashboards\\FixtureMessageSource'];

file_put_contents($path, '<?php return '.var_export($config, true).';'.PHP_EOL);
