<?php

use App\Http\Controllers\Admin\OperationalFormDeletionController;
use App\Http\Controllers\Admin\OperationalFormDocumentController;
use App\Http\Controllers\Admin\PersonnelRequestAttachmentController as AdminPersonnelRequestAttachmentController;
use App\Http\Controllers\Admin\QueueStatusController;
use App\Http\Controllers\Admin\VideoConferenceHealthController;
use App\Http\Controllers\Api\StationInventoryController;
use App\Http\Controllers\Employee\OperationalForms\EmployeeLookupController;
use App\Http\Controllers\Employee\OperationalForms\FormDocumentController;
use App\Http\Controllers\Employee\OperationalForms\FormGenerationController;
use App\Http\Controllers\Employee\OperationalForms\FormRecordController;
use App\Http\Controllers\Employee\OperationalForms\FormUploadController;
use App\Http\Controllers\Employee\OperationalForms\FrocImportController;
use App\Http\Controllers\Employee\PersonnelRequestAttachmentController;
use App\Http\Controllers\Employee\PersonnelRequestDetailController;
use App\Http\Controllers\Employee\PersonnelRequestResponseController;
use App\Http\Controllers\Employee\PersonnelRosterSearchController;
use App\Http\Controllers\Employee\VideoConferencing\ConferenceConnectivityFailureController;
use App\Http\Controllers\Employee\VideoConferencing\ConferenceLeaveController;
use App\Http\Controllers\Employee\VideoConferencing\ConferenceSessionController;
use App\Http\Controllers\Employee\VideoConferencing\ConferenceTokenController;
use App\Http\Controllers\Employee\VideoConferencing\MuteAllStationsController;
use App\Http\Controllers\Employee\VideoConferencing\StationMicrophoneController;
use App\Http\Controllers\IncidentsController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\Webhooks\LiveKitWebhookController;
use App\Http\Controllers\Workgroup\FileDownloadController;
use App\Http\Middleware\EnsureEmployeeAuthenticated;
use App\Http\Middleware\ForcePasswordChangeMiddleware;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Public Security & Standards trust page — no auth required, indexable.
Route::view('/security-standards', 'security-standards')
    ->name('security-standards');

// Public incident feed — proxies PulsePoint Worker with 60s server-side cache
Route::get('/api/incidents', [IncidentsController::class, 'index'])->name('incidents.index');

// CSP violation report sink — receives reports from the report-only header in
// SecurityHeaders middleware. Browsers POST application/csp-report or
// application/reports+json. We log to the laravel channel so the 7-day clean
// window before promoting to enforcing CSP is observable. Always 204 (no body).
// VerifyCsrfToken exclusion is handled because Filament's web group does NOT
// run on this route (it's outside the admin panel + has no session bootstrap).
Route::post('/_csp-report', [\App\Http\Controllers\CspReportController::class, 'store'])
    ->middleware('throttle:30,1')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->name('csp.report');

// Fallback login route (required by Filament export download middleware)
Route::get('/login', function () {
    return redirect('/admin/login');
})->name('login');

Route::prefix('employee/video-conferencing/api')
    ->middleware(['auth:employee', ForcePasswordChangeMiddleware::class, 'conference.enabled', 'throttle:conference-controls'])
    ->name('employee.video-conferencing.api.')
    ->group(function (): void {
        Route::post('/sessions', ConferenceSessionController::class)->name('sessions');
        Route::post('/connectivity-failures', ConferenceConnectivityFailureController::class)
            ->name('connectivity-failures');
        Route::post('/sessions/{session}/token', ConferenceTokenController::class)
            ->middleware('throttle:conference-tokens')
            ->name('tokens');
        Route::post('/participations/{participation}/leave', ConferenceLeaveController::class)->name('leave');
        Route::post('/sessions/{session}/moderation/mute-stations', MuteAllStationsController::class)->name('mute-stations');
        Route::post('/sessions/{session}/moderation/stations/{station}/microphone', StationMicrophoneController::class)->name('station-microphone');
    });

Route::post('/webhooks/livekit', LiveKitWebhookController::class)
    ->middleware(['conference.enabled', 'throttle:120,1'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->name('webhooks.livekit');

Route::prefix('employee')
    ->middleware([EnsureEmployeeAuthenticated::class, ForcePasswordChangeMiddleware::class])
    ->name('employee.personnel-requests.')
    ->group(function (): void {
        Route::get('/personnel-roster/search', PersonnelRosterSearchController::class)
            ->middleware('throttle:60,1')
            ->name('roster.search');
        Route::get('/my-requests/{personnelRequest}', PersonnelRequestDetailController::class)->name('show');
        Route::post('/personnel-requests/{personnelRequest}/attachments', [PersonnelRequestAttachmentController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('attachments.store');
        Route::post('/personnel-requests/{personnelRequest}/respond', PersonnelRequestResponseController::class)
            ->middleware('throttle:20,1')
            ->name('respond');
        Route::get('/personnel-request-attachments/{attachment}', [PersonnelRequestAttachmentController::class, 'download'])
            ->middleware('throttle:30,1')
            ->name('attachments.download');
    });

Route::get('/admin/video-conferencing/health', VideoConferenceHealthController::class)
    ->middleware(['auth:web', 'throttle:30,1'])
    ->name('admin.video-conferencing.health');

Route::get('/admin/personnel-request-attachments/{attachment}', AdminPersonnelRequestAttachmentController::class)
    ->middleware(['auth:web', 'throttle:30,1'])
    ->name('admin.personnel-request-attachments.download');

Route::prefix('employee/forms/api')
    ->middleware(['auth:employee', ForcePasswordChangeMiddleware::class, 'throttle:120,1'])
    ->name('employee.forms.api.')
    ->group(function (): void {
        Route::get('/form-types', [FormRecordController::class, 'formTypes'])->name('form-types');
        Route::get('/employees/search', EmployeeLookupController::class)
            ->middleware('throttle:60,1,operational-forms-employee-search')
            ->name('employees.search');
        Route::post('/froc/import-preview', FrocImportController::class)
            ->middleware('throttle:10,1,operational-forms-froc-import')
            ->name('froc.import-preview');
        Route::get('/records', [FormRecordController::class, 'index'])->name('records.index');
        Route::post('/records', [FormRecordController::class, 'store'])->name('records.store');
        Route::post('/uploads', FormUploadController::class)
            ->middleware('throttle:10,1,operational-forms-upload')
            ->name('uploads.store');
        Route::post('/records/{record}/froc/import', [FrocImportController::class, 'apply'])
            ->middleware('throttle:10,1,operational-forms-froc-import')
            ->name('records.froc.import');
        Route::post('/records/{record}/froc/import/{import}/undo', [FrocImportController::class, 'undo'])
            ->name('records.froc.import.undo');
        Route::get('/records/{record}', [FormRecordController::class, 'show'])->name('records.show');
        Route::get('/records/{record}/documents', [FormRecordController::class, 'documents'])->name('records.documents');
        Route::patch('/records/{record}', [FormRecordController::class, 'update'])->name('records.update');
        Route::delete('/records/{record}', [FormRecordController::class, 'destroy'])->name('records.destroy');
        Route::post('/records/{record}/generate', FormGenerationController::class)
            ->middleware('throttle:10,1,operational-forms-generate')
            ->name('records.generate');
        Route::post('/records/{record}/complete', FormGenerationController::class)
            ->middleware('throttle:10,1,operational-forms-complete')
            ->name('records.complete');
        Route::get('/records/{record}/generation/{job}', [FormGenerationController::class, 'status'])
            ->name('records.generation.status');
        Route::get('/documents/{document}/preview', [FormDocumentController::class, 'preview'])
            ->name('documents.preview');
        Route::get('/documents/{document}/download', [FormDocumentController::class, 'download'])
            ->name('documents.download');
    });

Route::prefix('admin/operational-forms/documents')
    ->middleware('auth:web')
    ->name('admin.operational-forms.documents.')
    ->group(function (): void {
        Route::get('/{document}/preview', [OperationalFormDocumentController::class, 'preview'])->name('preview');
        Route::get('/{document}/download', [OperationalFormDocumentController::class, 'download'])->name('download');
        Route::delete('/{document}', [OperationalFormDeletionController::class, 'document'])->name('destroy');
    });

Route::delete('/admin/operational-forms/records/{record}', [OperationalFormDeletionController::class, 'record'])
    ->middleware('auth:web')
    ->name('admin.operational-forms.records.destroy');

// Pump Simulator - Public route for training
Route::view('/pump-simulator', 'pump-simulator')->name('pump-simulator');

// Apparatus Layout Planner - Public tool
Route::view('/apparatus-layout', 'apparatus-layout')->name('apparatus-layout');

// Serve manifest.json with no-cache headers to bypass CDN caching
Route::get('/manifest.json', function () {
    $response = response()->file(public_path('manifest.json'), [
        'Content-Type' => 'application/manifest+json',
    ]);
    $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    $response->headers->set('Expires', '0');

    return $response;
});

// --- Admin Desktop-PWA -----------------------------------------------------
// These two routes serve the scoped admin manifest + service worker with
// the correct Content-Type and Service-Worker-Allowed scope header. The
// raw files live in public/admin-pwa/ so they survive `php artisan
// optimize` and the Vite manifest is untouched.
// Kill-switch: comment out either route to disable admin PWA installs.
Route::get('/admin-pwa/manifest.webmanifest', function () {
    return response()->file(public_path('admin-pwa/manifest.webmanifest'), [
        'Content-Type' => 'application/manifest+json',
        'Cache-Control' => 'public, max-age=300, must-revalidate',
    ]);
});

// Keep the worker URL inside its intended scope. Production web servers may
// serve /admin-pwa/* directly and bypass Laravel's Service-Worker-Allowed
// response header, which makes browsers reject an /admin/ scope.
Route::get('/admin/service-worker.js', function () {
    return response()->file(public_path('admin-pwa/service-worker.js'), [
        'Content-Type' => 'application/javascript; charset=utf-8',
        'Service-Worker-Allowed' => '/admin/',
        'Cache-Control' => 'no-cache, must-revalidate',
    ]);
});

Route::get('/admin/pulse/queues.json', QueueStatusController::class)
    ->middleware(['auth', 'throttle:60,1'])
    ->name('admin.queue-status');

Route::get('/admin-pwa/service-worker.js', function () {
    return response()->file(public_path('admin-pwa/service-worker.js'), [
        'Content-Type' => 'application/javascript; charset=utf-8',
        'Service-Worker-Allowed' => '/admin/',
        'Cache-Control' => 'no-cache, must-revalidate',
    ]);
});
// --- End Admin Desktop-PWA -------------------------------------------------

// Daily Checkout SPA - catch-all for React Router
Route::get('/daily/{path?}', function () {
    return response()->file(public_path('daily/index.html'), [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
        'Expires' => '0',
    ]);
})->where('path', '.+');

Route::get('/__version', function () {
    $shaFile = base_path('.git-sha');
    $sha = file_exists($shaFile) ? trim(file_get_contents($shaFile)) : 'unknown';
    $buildTimeFile = base_path('.build-time');
    $buildTime = file_exists($buildTimeFile) ? trim(file_get_contents($buildTimeFile)) : 'unknown';

    return response()->json([
        'git_sha' => $sha,
        'build_time' => $buildTime,
    ]);
})->middleware('auth');

// Station Inventory PDF Download
Route::get('/inventory-pdf/{submission}', [StationInventoryController::class, 'downloadPdf'])
    ->name('download-inventory-pdf')
    ->middleware('auth');

// Workgroup File Downloads & Preview
Route::get('/workgroup/file/{file}/download', [FileDownloadController::class, 'downloadFile'])
    ->name('workgroup.file.download')
    ->middleware(['auth', 'workgroup.access']);

Route::get('/workgroup/file/{file}/preview', [FileDownloadController::class, 'previewFile'])
    ->name('workgroup.file.preview')
    ->middleware(['auth', 'workgroup.access']);

Route::get('/workgroup/shared-upload/{upload}/download', [FileDownloadController::class, 'downloadSharedUpload'])
    ->name('workgroup.shared-upload.download')
    ->middleware(['auth', 'workgroup.access']);

// SAVER Report — Print-ready view
Route::get('/workgroup/saver-report', function () {
    $workgroup = \App\Models\Workgroup::first();
    $aiService = app(\App\Services\Workgroup\WorkgroupAIService::class);

    $reportHtml = $aiService->getCachedSaverReport($workgroup?->id ?? 0);

    return view('filament.workgroup.pages.saver-report', [
        'reportHtml' => $reportHtml,
        'workgroupName' => $workgroup?->name ?? 'MBFD Workgroup',
        'sessionName' => 'All Sessions',
        'generatedAt' => now()->format('F j, Y'),
    ]);
})->name('workgroup.saver-report')->middleware(['auth', 'workgroup.access']);

// Workgroup Analysis Report — Gemini-generated standalone page
Route::view('/workgroups/analysis-report', 'workgroup.analysis-report')
    ->name('workgroup.analysis-report')
    ->middleware(['auth', 'workgroup.access']);

// Workgroup Data Dashboard — React-based Gemini dashboard
Route::view('/workgroups/data-dashboard', 'workgroup.data-dashboard')
    ->name('workgroup.data-dashboard')
    ->middleware(['auth', 'workgroup.access']);

// Mid-Mount L1 Proposed Inventory — Self-contained React/SheetJS dashboard
Route::view('/workgroups/l1-inventory', 'workgroup.l1-inventory')
    ->name('workgroup.l1-inventory')
    ->middleware(['auth', 'workgroup.access']);

// Workgroup Final Session Presentation — Reveal.js slide deck
Route::view('/workgroups/final-presentation', 'workgroup.final-presentation')
    ->name('workgroup.final-presentation')
    ->middleware(['auth', 'workgroup.access']);

// MBFD Workgroup Evaluation Results — Professional Impeccable report with PDF export
Route::view('/workgroups/evaluation-report', 'workgroup.evaluation-report')
    ->name('workgroup.evaluation-report')
    ->middleware(['auth', 'workgroup.access']);

// MBFD Workgroup Final Recommendations — Final Selection & Implementation Report
Route::view('/workgroups/final-recommendations', 'workgroup.final-recommendations')
    ->name('workgroup.final-recommendations')
    ->middleware(['auth', 'workgroup.access']);

// MBFD Workgroup Summary — Full evaluation report with PDF export
Route::view('/workgroups/workgroup-summary', 'workgroup.workgroup-summary')
    ->name('workgroup.workgroup-summary')
    ->middleware(['auth', 'workgroup.access']);

// Workgroup Results CSV Export (authenticated)
Route::get('/workgroup-export/{tableKey}', function (string $tableKey, \Illuminate\Http\Request $request) {
    $sessionId = $request->query('session_id') ?: null;
    $evalService = app(\App\Services\Workgroup\EvaluationService::class);

    if (str_starts_with($tableKey, 'category_')) {
        $categoryName = urldecode(str_replace('category_', '', $tableKey));
        $results = $evalService->getSessionResults($sessionId);
        $targetCat = collect($results['rankable_categories'])->first(fn ($c) => $c['category_name'] === $categoryName);

        return response()->streamDownload(function () use ($targetCat) {
            $h = fopen('php://output', 'w');
            fputcsv($h, ['Rank', 'Product', 'Manufacturer', 'Model', 'Overall Score', 'Responses', 'Meets Threshold']);
            if ($targetCat) {
                foreach ($targetCat['rankings'] as $i => $item) {
                    fputcsv($h, [$i + 1, $item['product']->name ?? '', $item['product']->manufacturer ?? '', $item['product']->model ?? '', $item['weighted_average'] ?? '', $item['response_count'] ?? '', $item['meets_threshold'] ? 'Yes' : 'No']);
                }
            }
            fclose($h);
        }, strtolower(str_replace(' ', '_', $categoryName)).'_rankings_'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    if ($tableKey === 'competitor_groups') {
        $wg = \App\Models\Workgroup::first();
        $sess = $sessionId ? \App\Models\WorkgroupSession::find($sessionId) : null;
        $rankings = $wg ? $evalService->getCompetitorGroupRankings($wg, $sess) : [];

        return response()->streamDownload(function () use ($rankings) {
            $h = fopen('php://output', 'w');
            fputcsv($h, ['Category', 'Group', 'Rank', 'Product', 'Brand', 'Avg Score', 'Responses']);
            foreach ($rankings as $c) {
                foreach ($c['groups'] as $g) {
                    foreach ($g['rankings'] as $i => $r) {
                        fputcsv($h, [$c['category_name'], $g['group_name'], $i + 1, $r['name'] ?? '', $r['brand'] ?? '', $r['avg_score'] ?? '', $r['response_count'] ?? '']);
                    }
                }
            }
            fclose($h);
        }, 'competitor_groups_'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    if ($tableKey === 'finalists') {
        $results = $evalService->getSessionResults($sessionId);

        return response()->streamDownload(function () use ($results) {
            $h = fopen('php://output', 'w');
            fputcsv($h, ['Category', 'Rank', 'Product', 'Manufacturer', 'Avg Score', 'Responses']);
            foreach ($results['rankable_categories'] as $c) {
                $top = collect($c['rankings'])->filter(fn ($r) => $r['meets_threshold'])->take(2);
                foreach ($top as $i => $item) {
                    fputcsv($h, [$c['category_name'], $i + 1, $item['product']->name ?? '', $item['product']->manufacturer ?? '', $item['weighted_average'] ?? '', $item['response_count'] ?? '']);
                }
            }
            fclose($h);
        }, 'finalists_'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    $granular = $evalService->getGranularToolGroupings($sessionId);
    $fn = $tableKey.'_results_'.now()->format('Y-m-d').'.csv';

    if ($tableKey === 't1_standalone') {
        $t1 = $granular['t1_standalone'] ?? null;

        return response()->streamDownload(function () use ($t1) {
            $h = fopen('php://output', 'w');
            fputcsv($h, ['Product', 'Brand', 'Overall', 'Capability', 'Usability', 'Affordability', 'Maintainability', 'Deployability', 'Responses']);
            if ($t1) {
                fputcsv($h, [$t1['name'] ?? '', $t1['brand'] ?? '', $t1['avg_score'] ?? '', $t1['saver_breakdown']['capability'] ?? '', $t1['saver_breakdown']['usability'] ?? '', $t1['saver_breakdown']['affordability'] ?? '', $t1['saver_breakdown']['maintainability'] ?? '', $t1['saver_breakdown']['deployability'] ?? '', $t1['response_count'] ?? '']);
            }
            fclose($h);
        }, $fn, ['Content-Type' => 'text/csv']);
    }

    if ($tableKey === 'brand_overall') {
        $brands = $granular['brand_overall'] ?? [];

        return response()->streamDownload(function () use ($brands) {
            $h = fopen('php://output', 'w');
            fputcsv($h, ['Rank', 'Brand', 'Overall Avg', 'Tools', 'Capability', 'Usability', 'Affordability', 'Maintainability', 'Deployability']);
            foreach ($brands as $b) {
                fputcsv($h, [$b['rank'] ?? '', $b['brand'] ?? '', $b['overall_avg'] ?? '', $b['tool_count'] ?? '', $b['saver_breakdown']['capability'] ?? '', $b['saver_breakdown']['usability'] ?? '', $b['saver_breakdown']['affordability'] ?? '', $b['saver_breakdown']['maintainability'] ?? '', $b['saver_breakdown']['deployability'] ?? '']);
            }
            fclose($h);
        }, $fn, ['Content-Type' => 'text/csv']);
    }

    $items = $granular[$tableKey] ?? [];

    return response()->streamDownload(function () use ($items) {
        $h = fopen('php://output', 'w');
        fputcsv($h, ['Rank', 'Product', 'Brand', 'Overall', 'Capability', 'Usability', 'Affordability', 'Maintainability', 'Deployability', 'Advance Yes', 'Advance No', 'Deal Breakers', 'Responses']);
        foreach ($items as $i => $item) {
            fputcsv($h, [$i + 1, $item['name'] ?? ($item['product']->name ?? ''), $item['brand'] ?? '', $item['avg_score'] ?? '', $item['capability_avg'] ?? '', $item['usability_avg'] ?? '', $item['affordability_avg'] ?? '', $item['maintainability_avg'] ?? '', $item['deployability_avg'] ?? '', $item['advance_yes'] ?? '', $item['advance_no'] ?? '', $item['deal_breakers'] ?? '', $item['response_count'] ?? '']);
        }
        fclose($h);
    }, $fn, ['Content-Type' => 'text/csv']);
})->name('workgroup.export.csv')->middleware(['auth', 'workgroup.access']);

// Workgroup Report PDF Exports (authenticated + workgroup-authorized)
Route::middleware(['auth', 'workgroup.access'])->group(function () {
    Route::get('/reports/executive-report/pdf', [ReportExportController::class, 'exportExecutiveReport'])->name('reports.executive.pdf');
    Route::get('/reports/saver-report/pdf', [ReportExportController::class, 'exportSaverReport'])->name('reports.saver.pdf');
});
