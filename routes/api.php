<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ApparatusController;
use App\Http\Controllers\Api\AdminMetricsController;
use App\Http\Controllers\Api\SmartUpdatesController;
use App\Http\Controllers\Api\InventoryChatController;
use App\Http\Controllers\Api\PushSubscriptionController;
use App\Http\Controllers\Api\TestNotificationController;
use App\Http\Controllers\Api\BigTicketRequestController;
use App\Http\Controllers\Api\StationInventoryController;
use App\Http\Controllers\Api\StationInventoryV2Controller;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('public')->middleware('throttle:60,1')->group(function () {
    Route::get('apparatuses', [ApparatusController::class, 'index']);
    Route::get('apparatuses/{apparatus}/checklist', [ApparatusController::class, 'checklist']);
    Route::post('apparatuses/{apparatus}/inspections', [ApparatusController::class, 'storeInspection']);
    
    // Public Station Routes for Daily Checkout SPA
    Route::get('stations', [\App\Http\Controllers\Api\StationController::class, 'index']);
    Route::get('stations/{station}', [\App\Http\Controllers\Api\StationController::class, 'show']);
    Route::get('stations/{station}/rooms', [\App\Http\Controllers\Api\StationController::class, 'rooms']);
    Route::get('stations/{station}/rooms/{room}/assets', [\App\Http\Controllers\Api\StationController::class, 'roomAssets']);
    Route::get('stations/{station}/apparatus', [\App\Http\Controllers\Api\StationController::class, 'apparatus']);
    Route::get('stations/{station}/projects', [\App\Http\Controllers\Api\StationController::class, 'projects']);
});

// Push notification routes (public VAPID key, authenticated subscription management)
Route::get('push/vapid-public-key', [PushSubscriptionController::class, 'vapidPublicKey']);

Route::middleware(['web', 'auth'])->group(function () {
    Route::post('push-subscriptions', [PushSubscriptionController::class, 'store']);
    Route::delete('push-subscriptions', [PushSubscriptionController::class, 'destroy']);
    Route::post('push/test', [TestNotificationController::class, 'sendTestNotification']);
});

Route::prefix('admin')->middleware('auth:sanctum')->group(function () {
    Route::get('metrics', [AdminMetricsController::class, 'index']);
    Route::get('smart-updates', [SmartUpdatesController::class, 'index'])->name('api.smart-updates');
    
    // NEW: Inventory Chat Assistant
    Route::post('ai/inventory-chat', [InventoryChatController::class, 'chat']);
    Route::post('ai/inventory-execute', [InventoryChatController::class, 'executeAction']);
    
    // NEW: Station Management Routes
    Route::apiResource('stations', \App\Http\Controllers\Api\StationController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
    Route::get('stations/{station}/rooms', [\App\Http\Controllers\Api\StationController::class, 'rooms']);
    Route::post('stations/{station}/rooms', [\App\Http\Controllers\Api\StationController::class, 'storeRoom']);
    Route::get('stations/{station}/rooms/{room}/assets', [\App\Http\Controllers\Api\StationController::class, 'roomAssets']);
    Route::post('stations/{station}/rooms/{room}/assets', [\App\Http\Controllers\Api\StationController::class, 'storeRoomAsset']);
    Route::get('stations/{station}/rooms/{room}/audits', [\App\Http\Controllers\Api\StationController::class, 'roomAudits']);
    Route::post('stations/{station}/rooms/{room}/audits', [\App\Http\Controllers\Api\StationController::class, 'storeRoomAudit']);
    Route::post('stations/{station}/rooms/{room}/audits/{audit}/complete', [\App\Http\Controllers\Api\StationController::class, 'completeAudit']);
    Route::get('stations/{station}/apparatus', [\App\Http\Controllers\Api\StationController::class, 'apparatus']);
    Route::get('stations/{station}/projects', [\App\Http\Controllers\Api\StationController::class, 'projects']);
});

// Big Ticket Requests
Route::post('/big-ticket-requests', [BigTicketRequestController::class, 'store']);
Route::get('/stations/{station}/big-ticket-requests', [BigTicketRequestController::class, 'index']);
Route::delete('/big-ticket-requests/{bigTicketRequest}', [BigTicketRequestController::class, 'destroy']);

// Station Inventory (v1 - legacy)
Route::get('/station-inventory/categories', [StationInventoryController::class, 'categories']);
Route::post('/station-inventory-submissions', [StationInventoryController::class, 'store']);
Route::get('/stations/{station}/station-inventory-submissions', [StationInventoryController::class, 'index']);
Route::get('/station-inventory-submissions/{submission}/pdf', [StationInventoryController::class, 'downloadPdf']);

// Station Inventory V2 (PIN-protected, real-time inventory management)
Route::prefix('v2')->middleware(['throttle:60,1'])->group(function () {
    // PIN verification endpoint (public)
    Route::post('/station-inventory/verify-pin', [StationInventoryV2Controller::class, 'verifyPin']);
    
    // Protected endpoints (require valid signed URL from PIN verification)
    Route::middleware('signed')->name('api.v2.station-inventory.')->group(function () {
        // Inventory list
        Route::get('/station-inventory/{stationId}', [StationInventoryV2Controller::class, 'getInventory'])
            ->name('access');
        
        // Update item count
        Route::put('/station-inventory/{stationId}/item/{itemId}', [StationInventoryV2Controller::class, 'updateItem']);
        
        // Supply requests
        Route::get('/station-inventory/{stationId}/supply-requests', [StationInventoryV2Controller::class, 'getSupplyRequests'])
            ->name('supply-requests');
        Route::post('/station-inventory/{stationId}/supply-requests', [StationInventoryV2Controller::class, 'createSupplyRequest']);
    });
});
