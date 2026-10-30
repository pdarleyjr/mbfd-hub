<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

/**
 * Application factory used only by Laravel's parallel test runner.
 *
 * `php artisan test --parallel` boots the application once in the ParaTest
 * coordinator process before any worker starts, and that process does not load
 * tests/bootstrap.php. Without this trait the coordinator reads the real `.env`
 * and its values (APP_DEBUG, member bootstrap secrets, ...) are inherited by
 * every worker, bypassing the isolated test environment. Marking the process as
 * a PHPUnit bootstrap makes bootstrap/app.php use the comment-only sentinel file
 * in the coordinator exactly as it does in each worker.
 */
trait CreatesApplication
{
    public function createApplication(): Application
    {
        if (! defined('MBFD_PHPUNIT_BOOTSTRAP')) {
            define('MBFD_PHPUNIT_BOOTSTRAP', true);
        }

        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
