<?php

declare(strict_types=1);

// Private pipe worker: Composer autoload only; no Laravel, database or logging boot.
$arguments = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : [];
if (PHP_SAPI !== 'cli' || count($arguments) !== 2 || ! is_string($arguments[1] ?? null)) {
    exit(2);
}

try {
    require dirname(__DIR__).'/vendor/autoload.php';
    $snapshot = (new App\Services\Training\CrewPlanner\TeleStaffDailyPdfParser)->parse($arguments[1]);
    fwrite(STDOUT, json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    exit(0);
} catch (Throwable) {
    // The parent exposes a generic message; never emit parser exceptions or text.
    exit(1);
}
