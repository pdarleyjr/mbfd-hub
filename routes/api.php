<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ApparatusController;
use App\Http\Controllers\Api\AdminMetricsController;
use App\Http\Controllers\Api\SmartUpdatesController;
use App\Http\Controllers\Api\InventoryChatController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('public')->middleware('throttle:60,1')->group(function () {
    Route::get('apparatuses', [ApparatusController::class, 'index']);
    Route::get('apparatuses/{apparatus}/checklist', [ApparatusController::class, 'checklist']);
    Route::post('apparatuses/{apparatus}/inspections', [ApparatusController::class, 'storeInspection']);
});

Route::prefix('admin')->middleware('auth:sanctum')->group(function () {
    Route::get('metrics', [AdminMetricsController::class, 'index']);
    Route::get('smart-updates', [SmartUpdatesController::class, 'index'])->name('api.smart-updates');
    
    // NEW: Inventory Chat Assistant
    Route::post('ai/inventory-chat', [InventoryChatController::class, 'chat']);
    Route::post('ai/inventory-execute', [InventoryChatController::class, 'executeAction']);
});
