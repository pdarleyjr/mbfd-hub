<?php

use App\Http\Controllers\Api\Admin\StationRequestController as AdminStationRequestController;
use App\Http\Controllers\Api\AdminMetricsController;
use App\Http\Controllers\Api\ApparatusController;
use App\Http\Controllers\Api\Bid\CredentialsController as BidCredentialsController;
use App\Http\Controllers\Api\BigTicketRequestController;
use App\Http\Controllers\Api\DatabaseAuditController;
use App\Http\Controllers\Api\Display\DisplayController;
use App\Http\Controllers\Api\FireEquipmentRequestController;
use App\Http\Controllers\Api\InventoryChatController;
use App\Http\Controllers\Api\Public\ApparatusLayout\ApparatusLayoutController;
use App\Http\Controllers\Api\PublicStationRequestController;
use App\Http\Controllers\Api\PushSubscriptionController;
use App\Http\Controllers\Api\SmartUpdatesController;
use App\Http\Controllers\Api\StationContextController;
use App\Http\Controllers\Api\StationInspectionController;
use App\Http\Controllers\Api\StationInventoryController;
use App\Http\Controllers\Api\StationInventoryV2Controller;
use App\Http\Controllers\Api\SupportChatProxyController;
use App\Http\Controllers\Api\TestNotificationController;
use App\Http\Controllers\Api\TrtInventoryController;
use App\Http\Controllers\Workgroup\WorkgroupAIController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// =========================================================================
// Database Audit Routes (Admin only - requires authentication)
// =========================================================================
Route::prefix('admin/audit')->middleware(['web', 'auth', 'admin.role:super_admin,admin', 'throttle:30,1'])->group(function () {
    Route::get('/users', [DatabaseAuditController::class, 'getUsers']);
    Route::get('/employees', [DatabaseAuditController::class, 'getEmployees']);
    Route::get('/roles', [DatabaseAuditController::class, 'getRolesAndPermissions']);
    Route::get('/summary', [DatabaseAuditController::class, 'getAuditSummary']);
    Route::get('/check-email/{email}', [DatabaseAuditController::class, 'checkEmailCase'])->where('email', '.*');
    Route::get('/check-employee-id/{employeeId}', [DatabaseAuditController::class, 'checkEmployeeIdCase']);
});

Route::prefix('public')->middleware('throttle:60,1')->group(function () {
    Route::get('apparatuses', [ApparatusController::class, 'index']);
    Route::get('apparatuses/{apparatus}/checklist', [ApparatusController::class, 'checklist']);
    Route::post('apparatuses/{apparatus}/inspections', [ApparatusController::class, 'storeInspection']);
    Route::get('employees/list', [ApparatusController::class, 'employees']);

    // Public Station Routes for Daily Checkout SPA
    Route::get('stations', [\App\Http\Controllers\Api\StationController::class, 'index']);
    Route::get('stations/{station}', [\App\Http\Controllers\Api\StationController::class, 'show']);
    Route::get('stations/{station}/rooms', [\App\Http\Controllers\Api\StationController::class, 'rooms']);
    Route::get('stations/{station}/rooms/{room}/assets', [\App\Http\Controllers\Api\StationController::class, 'roomAssets']);
    Route::get('stations/{station}/apparatus', [\App\Http\Controllers\Api\StationController::class, 'apparatus']);
    Route::get('stations/{station}/projects', [\App\Http\Controllers\Api\StationController::class, 'projects']);
    Route::get('stations/{station}/inspections', [\App\Http\Controllers\Api\StationController::class, 'stationInspections']);
    Route::get('stations/{station}/apparatus-inspections', [\App\Http\Controllers\Api\StationController::class, 'apparatusInspections']);
    Route::get('stations/{station}/equipment-requests', [\App\Http\Controllers\Api\StationController::class, 'equipmentRequests']);
    Route::get('stations/{station}/requests', [PublicStationRequestController::class, 'index']);
    Route::get('stations/{station}/activity', [StationContextController::class, 'activity']);
    Route::get('stations/{station}/rooms/{room}/profile', [StationContextController::class, 'roomProfile']);
    Route::get('station-requests/{stationRequest}', [PublicStationRequestController::class, 'show']);
    Route::get('stations/{station}/gas-meters', [\App\Http\Controllers\Api\StationController::class, 'gasMeters']);

    // Apparatus Layout Planner (public read, auth write)
    Route::prefix('apparatus-layout')->group(function () {
        Route::get('tools', [ApparatusLayoutController::class, 'getTools']);
        Route::get('compartments/{apparatusId}', [ApparatusLayoutController::class, 'getCompartments']);
        Route::get('snapshots/{apparatusId}', [ApparatusLayoutController::class, 'getSnapshots']);
    });
});

// =========================================================================
// Command Display API (NEW, ADDITIVE, READ-ONLY) — feeds the separate
// staff-only command-display dashboard. GET-only: the display.readonly
// middleware rejects any non-GET verb with 405. No app login here; the
// display origin is gated by Cloudflare Access at the edge plus a shared
// secret from the CF Functions gateway. All payloads are redacted of
// sensitive/personnel data EXCEPT the dedicated personnel endpoint (allowed
// because the surface is staff-only behind Access).
// =========================================================================
Route::prefix('display')->middleware(['display.token', 'display.readonly', 'throttle:120,1'])->group(function () {
    Route::get('snapshot', [DisplayController::class, 'overview']);
    Route::get('stations', [DisplayController::class, 'stations']);
    Route::get('stations/{station}', [DisplayController::class, 'stationDetail']);
    Route::get('stations/{station}/apparatus', [DisplayController::class, 'stationApparatus']);
    Route::get('stations/{station}/personnel', [DisplayController::class, 'stationPersonnel']);
    Route::get('stations/{station}/submissions', [DisplayController::class, 'stationSubmissions']);
    Route::get('stations/{station}/camera-feeds', [DisplayController::class, 'stationCameraFeeds']);
    Route::get('critical-items', [DisplayController::class, 'criticalItems']);
    Route::get('ai-snapshot', [DisplayController::class, 'aiSnapshot']);
    Route::get('cameras', [DisplayController::class, 'cameras']);
    Route::get('incidents', [DisplayController::class, 'incidents']);
    Route::get('health', [DisplayController::class, 'health']);

    // Catch-all for any mutating verb on a display path. Registered AFTER the GET
    // routes so reads are unaffected. Routed to a controller method (not a closure)
    // so the route table remains cacheable via `php artisan route:cache`.
    Route::addRoute(['POST', 'PUT', 'PATCH', 'DELETE'], '{any}', [DisplayController::class, 'methodNotAllowed'])
        ->where('any', '.*');
});

Route::prefix('public')->middleware('throttle:10,1')->group(function () {
    Route::post('support-chat', [SupportChatProxyController::class, 'chat']);
});

// Public Station Inspection submission (stricter rate limit)
Route::prefix('public')->middleware('throttle:10,1')->group(function () {
    Route::post('station_inspection', [StationInspectionController::class, 'storePublic']);
    Route::post('fire_equipment_request', [FireEquipmentRequestController::class, 'storePublic']);
    Route::post('station_request', [PublicStationRequestController::class, 'store']);
});

// TRT Trailer Inventory (public read)
Route::prefix('public')->middleware('throttle:60,1')->group(function () {
    Route::get('trt-inventory/catalog', [TrtInventoryController::class, 'catalogIndex']);
});

// TRT Trailer Inventory (public write - stricter rate limit)
Route::prefix('public')->middleware('throttle:10,1')->group(function () {
    Route::post('trt-inventory/submit', [TrtInventoryController::class, 'submit']);
});

// TRT Trailer Inventory (admin)
Route::prefix('admin/trt-inventory')->middleware(['web', 'auth', 'admin.role:super_admin,admin,logistics_admin'])->group(function () {
    Route::get('sessions', [TrtInventoryController::class, 'sessions']);
    Route::get('sessions/{id}', [TrtInventoryController::class, 'sessionDetail']);
});

// Push notification routes (public VAPID key, authenticated subscription management)
Route::get('push/vapid-public-key', [PushSubscriptionController::class, 'vapidPublicKey']);

Route::middleware(['web', 'auth'])->group(function () {
    Route::post('push-subscriptions', [PushSubscriptionController::class, 'store']);
    Route::delete('push-subscriptions', [PushSubscriptionController::class, 'destroy']);
    Route::post('push/test', [TestNotificationController::class, 'sendTestNotification']);
});

// Admin lookup endpoints — powers the desktop-PWA Dexie prefetch + future typeahead.
// Uses the Filament admin cookie session (web + auth) so the installed PWA
// authenticates identically to the browser admin. Role check via admin.role
// middleware AND inline in LookupController (defense-in-depth). Rate limited
// at 60 req/min per IP to bound abuse if a token leaks.
Route::middleware(['web', 'auth', 'admin.role:super_admin,admin', 'throttle:60,1'])
    ->prefix('admin/lookups')
    ->group(function () {
        Route::get('stations', [\App\Http\Controllers\Api\Admin\LookupController::class, 'stations']);
        Route::get('apparatus', [\App\Http\Controllers\Api\Admin\LookupController::class, 'apparatus']);
        Route::get('personnel', [\App\Http\Controllers\Api\Admin\LookupController::class, 'personnel']);
    });

Route::prefix('admin')->middleware(['auth:sanctum', 'admin.role:super_admin,admin,logistics_admin', 'throttle:60,1'])->group(function () {
    Route::get('metrics', [AdminMetricsController::class, 'index']);
    Route::get('smart-updates', [SmartUpdatesController::class, 'index'])->name('api.smart-updates');
    Route::get('station-requests', [AdminStationRequestController::class, 'index']);
    Route::get('station-requests/{stationRequest}', [AdminStationRequestController::class, 'show']);
    Route::patch('station-requests/{stationRequest}/transition', [AdminStationRequestController::class, 'transition']);

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

    // Phase 5: Fire Equipment Requests & Station Inspections
    Route::apiResource('fire-equipment-requests', FireEquipmentRequestController::class);
    Route::apiResource('station-inspections', StationInspectionController::class);

    // Apparatus Layout Planner (authenticated write routes)
    Route::prefix('apparatus-layout')->group(function () {
        Route::post('snapshots', [ApparatusLayoutController::class, 'saveSnapshot']);
        Route::delete('snapshots/{snapshotId}', [ApparatusLayoutController::class, 'deleteSnapshot']);
        Route::post('autosave', [ApparatusLayoutController::class, 'autoSave']);
    });
});

// Big Ticket Requests
Route::middleware('throttle:60,1')->group(function () {
    Route::post('/big-ticket-requests', [BigTicketRequestController::class, 'store']);

    // Station Inventory (v1 - legacy)
    Route::get('/station-inventory/categories', [StationInventoryController::class, 'categories']);
    Route::post('/station-inventory-submissions', [StationInventoryController::class, 'store']);
});

Route::middleware(['web', 'auth', 'admin.role:super_admin,admin,logistics_admin', 'throttle:60,1'])->group(function () {
    Route::get('/stations/{station}/big-ticket-requests', [BigTicketRequestController::class, 'index']);
    Route::get('/stations/{station}/station-inventory-submissions', [StationInventoryController::class, 'index']);
    Route::get('/station-inventory-submissions/{submission}/pdf', [StationInventoryController::class, 'downloadPdf']);
});

Route::delete('/big-ticket-requests/{bigTicketRequest}', [BigTicketRequestController::class, 'destroy'])
    ->middleware(['auth:sanctum', 'admin.role:super_admin,admin,logistics_admin', 'throttle:30,1']);

// SECURITY (H-01): approving a pending-review apparatus inspection is the only
// path that may flip an apparatus Out of Service. Authenticated + authorized only.
Route::post('/apparatus-inspections/{inspection}/approve', [ApparatusController::class, 'approveInspection'])
    ->middleware(['auth:sanctum', 'admin.role:super_admin,admin,logistics_admin', 'throttle:30,1']);

// Station Inventory V2 (PIN-protected, real-time inventory management)
// =========================================================================
// MBFD Bid Cloudflare Worker bridge — POST /api/v2/verify-credentials
//
// The bid Worker calls this endpoint to validate a member's portal
// credentials when they log into https://bid.mbfdhub.com /
// https://staging.bid.mbfdhub.com. Gated by a shared bearer token
// (BID_READER_TOKEN env on this side, PORTAL_BID_READER on the Worker side).
// =========================================================================
Route::prefix('v2')->middleware(['throttle:30,1', 'verify.bid.token'])->group(function () {
    Route::post('/verify-credentials', [BidCredentialsController::class, 'verifyCredentials'])
        ->name('api.v2.bid.verify-credentials');
});

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

// =========================================================================
// Workgroup AI Routes — Eval analysis & AI summaries (separate from chatbot)
// Requires authentication (Filament session auth via 'web' middleware)
// =========================================================================
Route::prefix('workgroup/ai')->middleware(['web', 'auth', 'workgroup.access', 'throttle:30,1'])->group(function () {
    Route::post('analyze-product/{productId}', [WorkgroupAIController::class, 'analyzeProduct']);
    Route::post('category-summary', [WorkgroupAIController::class, 'categorySummary']);
    Route::post('executive-report', [WorkgroupAIController::class, 'executiveReport']);
    Route::post('vectorize-upload/{uploadId}', [WorkgroupAIController::class, 'vectorizeUpload']);
});
