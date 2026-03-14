<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\StationInventoryController;
use App\Http\Controllers\Workgroup\FileDownloadController;
use App\Http\Controllers\ReportExportController;

Route::get('/', function () {
    return view('welcome');
});

// Pump Simulator - Public route for training
Route::view('/pump-simulator', 'pump-simulator')->name('pump-simulator');

// Serve manifest.json with no-cache headers to bypass CDN caching
Route::get('/manifest.json', function () {
    $response = response()->file(public_path('manifest.json'), [
        'Content-Type' => 'application/manifest+json',
    ]);
    $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    $response->headers->set('Expires', '0');
    return $response;
});

// Daily Checkout SPA - catch-all for React Router
Route::get('/daily/{path?}', function () {
    return response()->file(public_path('daily/index.html'), [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
        'Expires' => '0',
    ]);
})->where('path', '.+');

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

// Workgroup File Downloads & Preview
Route::get('/workgroup/file/{file}/download', [FileDownloadController::class, 'downloadFile'])
    ->name('workgroup.file.download')
    ->middleware('auth');

Route::get('/workgroup/file/{file}/preview', [FileDownloadController::class, 'previewFile'])
    ->name('workgroup.file.preview')
    ->middleware('auth');

Route::get('/workgroup/shared-upload/{upload}/download', [FileDownloadController::class, 'downloadSharedUpload'])
    ->name('workgroup.shared-upload.download')
    ->middleware('auth');

// SAVER Report — Print-ready view
Route::get('/workgroup/saver-report', function () {
    $workgroup = \App\Models\Workgroup::first();
    $aiService = app(\App\Services\Workgroup\WorkgroupAIService::class);

    $reportHtml = $aiService->getCachedSaverReport($workgroup?->id ?? 0);

    return view('filament.workgroup.pages.saver-report', [
        'reportHtml' => $reportHtml,
        'workgroupName' => $workgroup?->name ?? 'MBFD Workgroup',
        'sessionName' => 'All Sessions',
        'generatedAt' => now()->format('F j, Y'),
    ]);
})->name('workgroup.saver-report')->middleware('auth');

// Workgroup Report PDF Exports (authenticated)
Route::middleware(['auth'])->group(function () {
    Route::get('/reports/executive-report/pdf', [ReportExportController::class, 'exportExecutiveReport'])->name('reports.executive.pdf');
    Route::get('/reports/saver-report/pdf', [ReportExportController::class, 'exportSaverReport'])->name('reports.saver.pdf');
});

