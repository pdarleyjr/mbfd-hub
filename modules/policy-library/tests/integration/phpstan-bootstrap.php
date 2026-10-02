<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Mbfd\PolicyLibrary\PolicyLibraryServiceProvider;

require __DIR__.'/bootstrap.php';

$app = require MBFD_POLICY_HUB_FIXTURE_ROOT.'/bootstrap/app.php';
$app->afterBootstrapping(
    RegisterProviders::class,
    static function (Application $app): void {
        $app->register(PolicyLibraryServiceProvider::class);
    },
);
$app->make(Kernel::class)->bootstrap();
