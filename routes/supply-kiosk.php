<?php

declare(strict_types=1);

use App\Http\Controllers\Ems\SupplyKioskController;
use App\Http\Middleware\Ems\EnsureSupplyKioskDevice;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| EMS supply room screen (docs/ems/supply-room-kiosk.md)
|--------------------------------------------------------------------------
| Required from routes/web.php (session, CSRF, encrypted cookies). No member signs in: the page opens anywhere,
| but every API call needs a paired screen's cookie. Pairing links come from Admin > Medical Supply Room.
*/

Route::prefix('supply-kiosk')->name('supply-kiosk.')->group(function (): void {
    Route::get('/', [SupplyKioskController::class, 'page'])->name('page');
    Route::get('/pair/{token}', [SupplyKioskController::class, 'pairPage'])->where('token', '[a-f0-9]{64}')->middleware('throttle:20,1,supply-kiosk-pair')->name('pair.show');
    Route::post('/pair/{token}', [SupplyKioskController::class, 'pair'])->where('token', '[a-f0-9]{64}')->middleware('throttle:10,1,supply-kiosk-pair-post')->name('pair');
    Route::post('/pair-code', [SupplyKioskController::class, 'pairWithCode'])->middleware('throttle:10,1,supply-kiosk-pair-code')->name('pair-code');

    Route::prefix('api')->middleware([EnsureSupplyKioskDevice::class, 'throttle:240,1,supply-kiosk'])->name('api.')->group(function (): void {
        Route::get('/bootstrap', [SupplyKioskController::class, 'bootstrap'])->name('bootstrap');
        Route::post('/withdrawals', [SupplyKioskController::class, 'withdraw'])->middleware('throttle:60,1,supply-kiosk-take')->name('withdrawals.store');
        Route::post('/withdrawals/{uuid}/undo', [SupplyKioskController::class, 'undo'])->whereUuid('uuid')->name('withdrawals.undo');
        Route::post('/heartbeat', [SupplyKioskController::class, 'heartbeat'])->name('heartbeat');
    });
});
