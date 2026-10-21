<?php

declare(strict_types=1);

// Private pipe worker: Composer autoload only; no Laravel, database or logging boot.
// Exit codes: 20 invalid report, 21 unavailable, 22 worker failure, 23 invalid output.
$arguments = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : [];
if (PHP_SAPI !== 'cli' || count($arguments) !== 2 || ! is_string($arguments[1] ?? null)) {
    exit(21);
}

try {
    @require dirname(__DIR__).'/vendor/autoload.php';
} catch (Throwable) {
    exit(21);
}

try {
    $snapshot = (new App\Services\Training\CrewPlanner\TeleStaffDailyPdfParser)->parse($arguments[1]);
    fwrite(STDOUT, json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    exit(0);
} catch (InvalidArgumentException) {
    exit(20);
} catch (JsonException) {
    exit(23);
} catch (Throwable) {
    // Stable exit codes only; never emit parser exceptions or confidential text.
    exit(22);
}
