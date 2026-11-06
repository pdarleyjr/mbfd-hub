<?php

declare(strict_types=1);

use App\Http\Controllers\Employee\AccidentReportController;
use App\Http\Controllers\Employee\MemorandumController;
use App\Http\Controllers\Personnel\PersonnelDocumentController;
use App\Http\Middleware\EnsureEmployeeAuthenticated;
use Illuminate\Support\Facades\Route;

// Personnel documents: one authorized route for every screen that links a stored personnel file.
Route::middleware(['auth:web', 'throttle:120,1'])->prefix('personnel-documents')->name('personnel-documents.')->group(function (): void {
    Route::get('{document:ulid}/view', [PersonnelDocumentController::class, 'view'])->name('view');
    Route::get('{document:ulid}/download', [PersonnelDocumentController::class, 'download'])->name('download');
});

// A memorandum PDF attached to a Support Services message: opened by the conversation's member or Support Services.
Route::get('employee-messages/{message}/attachment', [PersonnelDocumentController::class, 'messageAttachment'])
    ->middleware(['auth:web', 'throttle:60,1'])->whereNumber('message')->name('employee-messages.attachment');

// Employee Portal: Vehicle Accident Report, Supervisor's Report of Accident, Interoffice Memorandum.
Route::prefix('employee/forms/api')
    ->middleware([EnsureEmployeeAuthenticated::class, 'throttle:120,1'])
    ->name('employee.forms.api.')
    ->group(function (): void {
        Route::get('/accidents/form', [AccidentReportController::class, 'form'])->name('accidents.form');
        Route::get('/accidents', [AccidentReportController::class, 'index'])->name('accidents.index');
        Route::post('/accidents', [AccidentReportController::class, 'store'])
            ->middleware('throttle:20,1,personnel-accident-start')->name('accidents.store');
        Route::get('/accidents/{report:ulid}', [AccidentReportController::class, 'show'])->name('accidents.show');
        Route::patch('/accidents/{report:ulid}', [AccidentReportController::class, 'update'])->name('accidents.update');
        Route::delete('/accidents/{report:ulid}', [AccidentReportController::class, 'destroy'])->name('accidents.destroy');
        Route::post('/accidents/{report:ulid}/submit', [AccidentReportController::class, 'submit'])
            ->middleware('throttle:10,1,personnel-accident-submit')->name('accidents.submit');
        Route::post('/accidents/{report:ulid}/supervisor', [AccidentReportController::class, 'completeSupervisor'])
            ->middleware('throttle:10,1,personnel-accident-submit')->name('accidents.supervisor');
        Route::get('/accidents/{report:ulid}/preview', [AccidentReportController::class, 'preview'])
            ->middleware('throttle:20,1,personnel-form-pdf')->name('accidents.preview');

        Route::get('/memorandum/form', [MemorandumController::class, 'form'])->name('memorandum.form');
        Route::post('/memorandum/pdf', [MemorandumController::class, 'pdf'])
            ->middleware('throttle:30,1,personnel-form-pdf')->name('memorandum.pdf');
        Route::post('/memorandum/support-services', [MemorandumController::class, 'sendToSupportServices'])
            ->middleware('throttle:10,1,personnel-memo-send')->name('memorandum.support-services');
        Route::post('/memorandum/email', [MemorandumController::class, 'emailCopy'])
            ->middleware('throttle:5,1,personnel-memo-send')->name('memorandum.email');
    });
