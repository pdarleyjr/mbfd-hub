<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\StationInventoryController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/daily', function () {
    $response = response()->file(public_path('daily/index.html'));
    $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    $response->headers->set('Expires', '0');
    return $response;
});

// Catch-all route for SPA with wildcard
Route::get('/daily/{any}', function () {
    $response = response()->file(public_path('daily/index.html'));
    $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    $response->headers->set('Expires', '0');
    return $response;
})->where('any', '.*');

Route::get('/__version', function() {
    $sha = cache()->remember('build_sha', 60, fn() => trim(shell_exec('git rev-parse HEAD') ?? 'unknown'));
    $branch = cache()->remember('build_branch', 60, fn() => trim(shell_exec('git rev-parse --abbrev-ref HEAD') ?? 'unknown'));
    $buildTime = cache()->remember('build_time', 60, fn() => now()->toIso8601String());
    
    return response()->json([
        'git_sha' => $sha,
        'branch' => $branch,
        'build_time' => $buildTime,
    ]);
});

// Station Inventory PDF Download
Route::get('/inventory-pdf/{submission}', [StationInventoryController::class, 'downloadPdf'])
    ->name('download-inventory-pdf')
    ->middleware('auth');

