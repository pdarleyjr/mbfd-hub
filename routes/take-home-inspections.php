<?php

declare(strict_types=1);

use App\Http\Controllers\TakeHomeInspections\TakeHomeInspectionController;
use App\Http\Controllers\TakeHomeInspections\TakeHomeInspectionFileController;
use App\Http\Controllers\TakeHomeInspections\TakeHomeInspectionLookupController;
use Illuminate\Support\Facades\Route;

/*
| Take Home Vehicle Inspections: any signed-in Hub account (no role or panel
| permission). Members see their own submissions; Fleet viewers see all.
| Authorization lives in App\Support\Fleet\TakeHomeInspectionAccess.
*/
Route::prefix('take-home-vehicle-inspections')
    ->middleware('auth:web')
    ->name('take-home-inspections.')
    ->group(function (): void {
        Route::get('/', [TakeHomeInspectionController::class, 'index'])->name('index');
        Route::get('/new', [TakeHomeInspectionController::class, 'create'])->name('create');
        Route::post('/', [TakeHomeInspectionController::class, 'store'])
            ->middleware('throttle:20,1')->name('store');

        Route::get('/lookup/vehicles', [TakeHomeInspectionLookupController::class, 'vehicles'])
            ->middleware('throttle:120,1')->name('vehicles.search');
        Route::get('/lookup/vehicles/{vehicle}', [TakeHomeInspectionLookupController::class, 'vehicle'])
            ->middleware('throttle:120,1')->whereNumber('vehicle')->name('vehicles.show');
        Route::post('/lookup/vehicles', [TakeHomeInspectionLookupController::class, 'storeVehicle'])
            ->middleware('throttle:10,60')->name('vehicles.store');
        Route::get('/lookup/employees', [TakeHomeInspectionLookupController::class, 'employees'])
            ->middleware('throttle:120,1')->name('employees.search');

        Route::post('/photos', [TakeHomeInspectionFileController::class, 'storePhoto'])
            ->middleware('throttle:60,1')->name('photos.store');
        Route::get('/photos/{photo:uuid}', [TakeHomeInspectionFileController::class, 'photo'])
            ->middleware('throttle:240,1')->whereUuid('photo')->name('photo');

        Route::get('/{inspection:reference}', [TakeHomeInspectionController::class, 'show'])
            ->where('inspection', 'THI-[0-9]{6}-[A-Z0-9]{6}')->name('show');
        Route::get('/{inspection:reference}/pdf', [TakeHomeInspectionFileController::class, 'pdf'])
            ->middleware('throttle:30,1')->where('inspection', 'THI-[0-9]{6}-[A-Z0-9]{6}')->name('pdf');
        Route::get('/{inspection:reference}/signature', [TakeHomeInspectionFileController::class, 'signature'])
            ->middleware('throttle:120,1')->where('inspection', 'THI-[0-9]{6}-[A-Z0-9]{6}')->name('signature');
    });
