<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ApparatusController;
use App\Http\Controllers\Api\AdminMetricsController;
use App\Http\Controllers\Api\SmartUpdatesController;
use App\Http\Controllers\Api\InventoryChatController;
use App\Http\Controllers\Api\PushSubscriptionController;
use App\Http\Controllers\Api\TestNotificationController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('public')->middleware('throttle:60,1')->group(function () {
    Route::get('apparatuses', [ApparatusController::class, 'index']);
    Route::get('apparatuses/{apparatus}/checklist', [ApparatusController::class, 'checklist']);
    Route::post('apparatuses/{apparatus}/inspections', [ApparatusController::class, 'storeInspection']);
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
