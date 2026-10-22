<?php

declare(strict_types=1);

use App\Http\Controllers\Fleet\VehicleDocumentFileController;
use App\Http\Controllers\Fleet\VehicleProfileExportController;
use Illuminate\Support\Facades\Route;

Route::prefix('fleet/document-review/{document}')->middleware(['auth:web', 'throttle:120,1,vehicle-document-review'])
    ->whereNumber('document')->name('vehicle-documents.')->group(function (): void {
        Route::get('/download', [VehicleDocumentFileController::class, 'reviewDownload'])->name('review-download');
        Route::get('/preview', [VehicleDocumentFileController::class, 'reviewPreview'])->name('review-preview');
    });

Route::prefix('fleet/vehicles/{vehicle}')
    ->middleware('auth:web')
    ->whereNumber('vehicle')
    ->name('vehicle-documents.')
    ->group(function (): void {
        Route::get('/documents/{document}/download', [VehicleDocumentFileController::class, 'download'])
            ->whereNumber('document')->middleware('throttle:120,1,vehicle-document-files')->name('download');
        Route::get('/documents/{document}/preview', [VehicleDocumentFileController::class, 'preview'])
            ->whereNumber('document')->middleware('throttle:120,1,vehicle-document-files')->name('preview');
        Route::get('/export', VehicleProfileExportController::class)
            ->middleware('throttle:10,1,vehicle-profile-export')->name('export');
    });
