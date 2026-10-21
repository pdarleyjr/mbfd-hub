<?php

declare(strict_types=1);

use App\Http\Controllers\Training\TrainingCrewPlannerUploadController;
use App\Http\Middleware\CanonicalHostRedirect;
use App\Http\Middleware\EnsureCityEmailReview;
use App\Http\Middleware\EnsureTrainingPanelAccess;
use App\Http\Middleware\ForceFilamentPasswordChange;
use App\Http\Middleware\TrainingRosterPrivateResponse;
use Illuminate\Support\Facades\Route;

Route::post('/training/crew-planner/upload', TrainingCrewPlannerUploadController::class)
    ->middleware([CanonicalHostRedirect::class, 'auth:web', EnsureTrainingPanelAccess::class,
        ForceFilamentPasswordChange::class, EnsureCityEmailReview::class,
        TrainingRosterPrivateResponse::class, 'throttle:6,1,crew-planner-upload:'])
    ->name('training.crew-planner.upload');
