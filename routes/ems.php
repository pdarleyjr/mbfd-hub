<?php

declare(strict_types=1);

use App\Http\Controllers\Ems\MasterInventoryController;
use App\Http\Controllers\Ems\MemberRequestController;
use App\Http\Controllers\Ems\StaffRequestController;
use App\Http\Controllers\Ems\StatisticsController;
use App\Http\Controllers\Ems\SupplyDataController;
use App\Http\Controllers\Ems\SupplyRoomController;
use App\Http\Middleware\Ems\EnsureEmsAccess;
use App\Http\Middleware\EnsureEmployeeAuthenticated;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| EMS inventory JSON API (docs/ems/ems-inventory-contract.md, "as built")
|--------------------------------------------------------------------------
| Required from routes/web.php, so the web middleware group (session, CSRF) applies.
| Every response uses the {ok, data, meta} / {ok:false, error} envelope; every write takes an
| idempotency key (Idempotency-Key header or idempotency_key field).
*/

// Employee Portal: any signed-in member, their own requests only.
Route::prefix('employee/forms/api/ems')
    ->middleware([EnsureEmployeeAuthenticated::class, 'throttle:60,1,ems-member'])
    ->name('employee.forms.api.ems.')
    ->group(function (): void {
        Route::get('/form', [MemberRequestController::class, 'form'])->name('form');
        Route::get('/requests', [MemberRequestController::class, 'index'])->name('requests.index');
        Route::post('/requests', [MemberRequestController::class, 'submit'])->middleware('throttle:10,1,ems-submit')->name('requests.store');
        Route::get('/requests/{uuid}', [MemberRequestController::class, 'show'])->whereUuid('uuid')->name('requests.show');
        Route::post('/requests/{uuid}/messages', [MemberRequestController::class, 'message'])->middleware('throttle:20,1,ems-message')->whereUuid('uuid')->name('requests.messages.store');
        Route::post('/requests/{uuid}/cancel', [MemberRequestController::class, 'cancel'])->middleware('throttle:10,1,ems-cancel')->whereUuid('uuid')->name('requests.cancel');
    });

// Captain 5 / EMS Admin: reads need `view`, writes need `act`, item maintenance needs `configure`.
Route::prefix('ems/api')
    ->middleware(['auth:web', 'throttle:120,1,ems-staff-read'])
    ->name('ems.api.')
    ->group(function (): void {
        Route::middleware(EnsureEmsAccess::class.':view')->group(function (): void {
            Route::get('/requests', [StaffRequestController::class, 'index'])->name('requests.index');
            Route::get('/requests/{uuid}', [StaffRequestController::class, 'show'])->whereUuid('uuid')->name('requests.show');

            Route::get('/supply/items', [SupplyRoomController::class, 'items'])->name('supply.items.index');
            Route::get('/supply/items/{item}', [SupplyRoomController::class, 'item'])->whereNumber('item')->name('supply.items.show');
            Route::get('/supply/locations', [SupplyRoomController::class, 'locations'])->name('supply.locations');
            Route::get('/supply/counts', [SupplyRoomController::class, 'counts'])->name('supply.counts.index');
            Route::get('/supply/reconciliation', [SupplyRoomController::class, 'reconciliationPreview'])->name('supply.reconciliation.preview');
            Route::get('/supply/advisories', [SupplyRoomController::class, 'advisories'])->name('supply.advisories');
            Route::get('/supply/orders', [SupplyRoomController::class, 'orders'])->name('supply.orders.index');
            Route::get('/supply/movements', [SupplyRoomController::class, 'movements'])->name('supply.movements');
            Route::get('/supply/snapshots', [SupplyRoomController::class, 'snapshots'])->name('supply.snapshots.index');
            Route::get('/supply/snapshots/{uuid}', [SupplyRoomController::class, 'snapshot'])->whereUuid('uuid')->name('supply.snapshots.show');
            Route::get('/supply/snapshots/{uuid}/export/xlsx', [SupplyRoomController::class, 'exportSnapshotExcel'])->whereUuid('uuid')->name('supply.snapshots.export.xlsx');
            Route::get('/supply/snapshots/{uuid}/export/pdf', [SupplyRoomController::class, 'exportSnapshotPdf'])->whereUuid('uuid')->name('supply.snapshots.export.pdf');

            Route::get('/statistics', StatisticsController::class)->name('statistics');

            // Supply Data: what was distributed from the supply room. Read only, so view access is enough.
            Route::middleware('throttle:60,1,ems-supply-data')->prefix('supply-data')->name('supply-data.')->group(function (): void {
                Route::get('/', [SupplyDataController::class, 'overview'])->name('overview');
                Route::get('/export.xlsx', [SupplyDataController::class, 'export'])->name('export');
                // 1 to 18 digits: a longer number cannot be an id, so it is a plain 404 instead of an integer overflow.
                Route::get('/items/{item}', [SupplyDataController::class, 'item'])->where('item', '[0-9]{1,18}')->name('items.show');
                Route::get('/units/{unitKey}', [SupplyDataController::class, 'unit'])->where('unitKey', '[^/]+')->name('units.show');
            });

            Route::get('/forms/reconcile/preview', [MasterInventoryController::class, 'reconcilePreview'])->name('forms.reconcile.preview');
        });

        // The master inventory and its count: Captain 5 and any administrator (EmsAccess::canEditInventory).
        Route::middleware(EnsureEmsAccess::class.':inventory_read')->group(function (): void {
            Route::get('/meta', [StaffRequestController::class, 'meta'])->name('meta');
            Route::get('/supply/master', [MasterInventoryController::class, 'index'])->name('supply.master.index');
            Route::get('/supply/count-form/{uuid}/review', [MasterInventoryController::class, 'reviewCount'])->whereUuid('uuid')->name('supply.count-form.review');
        });

        Route::middleware([EnsureEmsAccess::class.':inventory_edit', 'throttle:60,1,ems-staff-write'])->group(function (): void {
            Route::post('/supply/master/items', [MasterInventoryController::class, 'store'])->name('supply.master.items.store');
            Route::patch('/supply/master/items', [MasterInventoryController::class, 'batch'])->name('supply.master.items.batch');
            Route::post('/supply/master/items/{item}/order', [MasterInventoryController::class, 'order'])->whereNumber('item')->name('supply.master.items.order');
            Route::patch('/supply/orders/{order}', [MasterInventoryController::class, 'updateOrder'])->whereNumber('order')->name('supply.orders.update');
            Route::post('/supply/orders/{order}/arrived', [MasterInventoryController::class, 'orderArrived'])->whereNumber('order')->name('supply.orders.arrived');
            Route::patch('/supply/master/items/{item}', [MasterInventoryController::class, 'update'])->whereNumber('item')->name('supply.master.items.update');
            Route::post('/supply/master/items/{item}/count', [MasterInventoryController::class, 'count'])->whereNumber('item')->name('supply.master.items.count');
            Route::post('/supply/count-form/start', [MasterInventoryController::class, 'startCount'])->name('supply.count-form.start');
            Route::put('/supply/count-form/{uuid}/entries', [MasterInventoryController::class, 'saveCount'])->whereUuid('uuid')->name('supply.count-form.entries');
            Route::post('/supply/count-form/{uuid}/submit', [MasterInventoryController::class, 'submitCount'])->whereUuid('uuid')->name('supply.count-form.submit');
            Route::delete('/supply/counts/{uuid}', [SupplyRoomController::class, 'discardCount'])->whereUuid('uuid')->name('supply.counts.discard');
        });

        Route::middleware([EnsureEmsAccess::class.':act', 'throttle:60,1,ems-staff-write'])->group(function (): void {
            Route::post('/requests/{uuid}/acknowledge', [StaffRequestController::class, 'acknowledge'])->whereUuid('uuid')->name('requests.acknowledge');
            Route::post('/requests/{uuid}/reject', [StaffRequestController::class, 'reject'])->whereUuid('uuid')->name('requests.reject');
            Route::post('/forms/reconcile', [MasterInventoryController::class, 'reconcile'])->name('forms.reconcile');

            Route::post('/requests/{uuid}/review', [StaffRequestController::class, 'review'])->whereUuid('uuid')->name('requests.review');
            Route::post('/requests/{uuid}/prepare', [StaffRequestController::class, 'prepare'])->whereUuid('uuid')->name('requests.prepare');
            Route::post('/requests/{uuid}/issue', [StaffRequestController::class, 'issue'])->whereUuid('uuid')->name('requests.issue');
            Route::post('/requests/{uuid}/not-issued', [StaffRequestController::class, 'notIssued'])->whereUuid('uuid')->name('requests.not-issued');
            Route::post('/requests/{uuid}/close', [StaffRequestController::class, 'close'])->whereUuid('uuid')->name('requests.close');
            Route::post('/requests/{uuid}/reopen', [StaffRequestController::class, 'reopen'])->whereUuid('uuid')->name('requests.reopen');
            Route::post('/requests/{uuid}/correct', [StaffRequestController::class, 'correct'])->whereUuid('uuid')->name('requests.correct');
            Route::post('/requests/{uuid}/messages', [StaffRequestController::class, 'message'])->whereUuid('uuid')->name('requests.messages.store');

            Route::post('/supply/counts', [SupplyRoomController::class, 'startCount'])->name('supply.counts.store');
            Route::put('/supply/counts/{uuid}/lines', [SupplyRoomController::class, 'saveCount'])->whereUuid('uuid')->name('supply.counts.lines');
            Route::post('/supply/counts/{uuid}/finalize', [SupplyRoomController::class, 'finalizeCount'])->whereUuid('uuid')->name('supply.counts.finalize');
            Route::post('/supply/reconciliation', [SupplyRoomController::class, 'confirmReconciliation'])->name('supply.reconciliation.confirm');
            Route::post('/supply/orders', [SupplyRoomController::class, 'placeOrder'])->name('supply.orders.store');
            Route::post('/supply/orders/{order}/receive', [SupplyRoomController::class, 'receiveOrder'])->whereNumber('order')->name('supply.orders.receive');
            Route::post('/supply/orders/{order}/cancel', [SupplyRoomController::class, 'cancelOrder'])->whereNumber('order')->name('supply.orders.cancel');
            Route::post('/supply/adjustments', [SupplyRoomController::class, 'adjust'])->name('supply.adjustments.store');
        });

        Route::middleware([EnsureEmsAccess::class.':configure', 'throttle:30,1,ems-staff-config'])->group(function (): void {
            Route::patch('/supply/items/{item}', [SupplyRoomController::class, 'updateItem'])->whereNumber('item')->name('supply.items.update');
        });
    });
