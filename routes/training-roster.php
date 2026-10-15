<?php

declare(strict_types=1);

use App\Http\Controllers\Training\TrainingKioskController;
use App\Http\Controllers\Training\TrainingRosterDocumentController;
use App\Http\Middleware\CanonicalHostRedirect;
use Illuminate\Support\Facades\Route;

Route::domain(config('training-roster.host'))->group(function (): void {
    Route::get('/', [TrainingKioskController::class, 'index'])->name('training.kiosk.home');
    Route::get('/r/{session:public_id}', [TrainingKioskController::class, 'index'])->name('training.kiosk.show');
    Route::prefix('/r/{session:public_id}')->middleware('throttle:120,1,training-global:')->group(function (): void {
        Route::get('/csrf', [TrainingKioskController::class, 'csrf']);
        Route::post('/authorize', [TrainingKioskController::class, 'authorizeDevice'])->middleware('throttle:10,1,training-authorize:');
        Route::get('/state', [TrainingKioskController::class, 'state']);
        Route::get('/candidates', [TrainingKioskController::class, 'candidates'])->middleware('throttle:30,1,training-candidates:');
        Route::post('/attendance', [TrainingKioskController::class, 'sign'])->middleware('throttle:60,1,training-attendance:');
        Route::post('/receipt', [TrainingKioskController::class, 'receipt']);
    });
});

Route::prefix('training-rosters')->middleware([CanonicalHostRedirect::class, 'auth:web', \App\Http\Middleware\TrainingRosterPrivateResponse::class])->group(function (): void {
    Route::get('/{session}/attendance/{attendance}/signature', [TrainingRosterDocumentController::class, 'signature'])->name('training.rosters.attendance.signature');
    Route::get('/{session}/export/{format}', [TrainingRosterDocumentController::class, 'export'])->name('training.rosters.export');
    Route::get('/{session}/versions/{version}/export/{format}', [TrainingRosterDocumentController::class, 'version'])->name('training.rosters.versions.export');
    Route::match(['get', 'post'], '/{session}/launch', [TrainingRosterDocumentController::class, 'launch'])->name('training.rosters.launch');
    Route::post('/{session}/revoke', [TrainingRosterDocumentController::class, 'revoke'])->name('training.rosters.revoke');
});
