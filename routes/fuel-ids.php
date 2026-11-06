<?php

declare(strict_types=1);

use App\Http\Controllers\Personnel\FuelIdSourceController;
use Illuminate\Support\Facades\Route;

// Fuel ID Numbers: the imported City fuel list, streamed only to holders of the Fuel ID view permission.
// The controller checks the permission on every request; the page's canAccess() is not the control.
Route::middleware(['web', 'auth:web'])->prefix('personnel/fuel-ids')->name('personnel.fuel-ids.')->group(function (): void {
    Route::get('sources/{source}/view', [FuelIdSourceController::class, 'view'])->whereNumber('source')->name('sources.view');
    Route::get('sources/{source}/download', [FuelIdSourceController::class, 'download'])->whereNumber('source')->name('sources.download');
});
