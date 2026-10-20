<?php

declare(strict_types=1);

use App\Http\Controllers\Training\TrainingPaperRosterController;
use App\Http\Middleware\CanonicalHostRedirect;
use App\Http\Middleware\TrainingRosterPrivateResponse;
use Illuminate\Support\Facades\Route;

Route::prefix('training-paper')->middleware([CanonicalHostRedirect::class, 'auth:web', TrainingRosterPrivateResponse::class])->group(function (): void {
    Route::get('/imports/{import}/source', [TrainingPaperRosterController::class, 'source'])->name('training.paper.source');
    Route::post('/entries/{entry}/review', [TrainingPaperRosterController::class, 'review'])->name('training.paper.review');
});
